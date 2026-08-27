<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Server\HttpBridge;
use Kode\Http\Response;
use Kode\Process\Http\Request as ProcessRequest;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * HttpBridge：kode/process 请求 ↔ PSR-7 双向转换测试。
 */
final class HttpBridgeTest extends TestCase
{
    public function testToPsr7ParsesMethodPathQuery(): void
    {
        $raw = "GET /users?page=2&q=foo HTTP/1.1\r\nHost: 127.0.0.1:8000\r\nAccept: */*\r\n\r\n";
        $req = ProcessRequest::fromRaw($raw, strpos($raw, "\r\n\r\n"));
        $psr = HttpBridge::toPsr7($req);

        self::assertSame('GET', $psr->getMethod());
        self::assertSame('/users', $psr->getUri()->getPath());
        self::assertSame('page=2&q=foo', $psr->getUri()->getQuery());
        self::assertSame('foo', $psr->getQueryParams()['q'] ?? null);
        self::assertSame('127.0.0.1:8000', $psr->getHeaderLine('Host'));
    }

    public function testToPsr7ReadsBody(): void
    {
        $body = json_encode(['name' => 'Kode']);
        $raw = "POST /users HTTP/1.1\r\nHost: x\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
        $req = ProcessRequest::fromRaw($raw, strpos($raw, "\r\n\r\n"));
        $psr = HttpBridge::toPsr7($req);

        self::assertSame('POST', $psr->getMethod());
        self::assertSame($body, (string) $psr->getBody());
    }

    public function testToRawProducesValidHttpResponse(): void
    {
        $psr = Response::make((string) json_encode(['code' => 0]), 200)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Request-Id', 'r1');

        $raw = HttpBridge::toRaw($psr);

        self::assertStringStartsWith('HTTP/1.1 200', $raw);
        self::assertStringContainsString('Content-Type: application/json', $raw);
        self::assertStringContainsString('X-Request-Id: r1', $raw);
        self::assertStringContainsString('{"code":0}', $raw);
    }

    public function testToRawHonorsProvidedProtocol(): void
    {
        $psr = Response::make('hi', 200)->withHeader('Content-Type', 'text/plain');

        // 默认协议版本为 1.1。
        self::assertStringStartsWith('HTTP/1.1 200', HttpBridge::toRaw($psr));

        // 旧客户端协商出 1.0 时，状态行应反映真实协议而非硬编码 1.1。
        self::assertStringStartsWith('HTTP/1.0 200', HttpBridge::toRaw($psr, '1.0'));
    }

    public function testToRawSanitizesHeaderInjection(): void
    {
        // 安全（v0.8.52）：响应头值中的 CR/LF/NUL 必须剥离，防应用层把用户可控数据
        // （如 Resp::redirect($userInput)）写入响应头时造成响应拆分。
        $psr = Response::make('', 302)
            ->withHeader('Location', "http://evil.example/\r\nX-Injected: 1\r\n\r\nstolen-body");

        $raw = HttpBridge::toRaw($psr);

        // 安全属性：CR/LF 被剥离后，注入内容不会形成新的头行或伪造第二个响应报文——
        // 整个恶意值被压平进单个 Location 头行内。
        self::assertSame(1, substr_count($raw, "Location:"), '必须只允许一个 Location 头行');
        self::assertSame(1, substr_count($raw, "HTTP/"), '不得出现被注入的第二个状态行');
        self::assertStringNotContainsString("X-Injected\r\n", $raw);
        self::assertStringNotContainsString("\r\n\r\nstolen-body\r\nHTTP/1.1", $raw);
        self::assertStringContainsString("Location: http://evil.example/", $raw);
    }

    public function testToRawOmitsContentLengthAndBodyFor204(): void
    {
        // RFC 9110 §8.6 / RFC 9112 §6.3：204 不得携带 Content-Length 与实体。
        $raw = HttpBridge::toRaw(Response::make('ignored', 204));

        self::assertStringStartsWith('HTTP/1.1 204 No Content', $raw);
        self::assertStringNotContainsString('Content-Length', $raw);
        self::assertStringEndsWith("\r\n\r\n", $raw);
    }

    public function testToRawUnknownStatusFallsBackToUnknownReason(): void
    {
        // 旧实现对未知状态码回退 'OK'（599 OK），语义误导；与 vendor HttpProtocol 对齐为 'Unknown'。
        $raw = HttpBridge::toRaw(Response::make('x', 599));

        self::assertStringStartsWith('HTTP/1.1 599 Unknown', $raw);
    }

    public function testEmitFastPathWritesRawForSmallBody(): void
    {
        // 架构红线（v0.8.42）：小响应走 rawBody 感知的快路径，由 toRaw 序列化后经 send($raw, true)
        // 直写连接，避免经 PSR-7 getBody() 二次物化——这是 /bench/json 的性能主路径。
        $response = Response::make('hi', 200)->withHeader('Content-Type', 'text/plain');
        $conn = $this->createMock(\Kode\Process\Runtime\ConnectionInterface::class);

        $conn->expects(self::once())
            ->method('send')
            ->with(self::stringStartsWith('HTTP/1.1 200'), true);
        $conn->expects(self::never())
            ->method('sendResponse');

        HttpBridge::emit($conn, $response);
    }

    public function testEmitDelegatesToConnectionSendResponseWhenGzip(): void
    {
        // 架构红线：仅当连接层确会触发自动 gzip（响应体 >= GZIP_MIN_SIZE 且 isGzipAuto()）时，
        // 才退回官方 sendResponse 以保留压缩能力；引擎专用写出由 kode/process 各 Driver 负责。
        $body = str_repeat('a', \Kode\Process\Protocol\HttpProtocol::GZIP_MIN_SIZE);
        $response = Response::make($body, 200)->withHeader('Content-Type', 'text/plain');
        $conn = $this->createMock(\Kode\Process\Runtime\ConnectionInterface::class);

        $conn->expects(self::once())
            ->method('isGzipAuto')
            ->willReturn(true);
        $conn->expects(self::once())
            ->method('sendResponse')
            ->with($response);
        $conn->expects(self::never())
            ->method('send');

        HttpBridge::emit($conn, $response);
    }
}
