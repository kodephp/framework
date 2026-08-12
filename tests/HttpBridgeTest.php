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
}
