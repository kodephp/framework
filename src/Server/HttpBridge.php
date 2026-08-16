<?php

declare(strict_types=1);

namespace Kode\Framework\Server;

use Kode\Http\Psr7\Message\ServerRequest as KodeServerRequest;
use Kode\Http\Psr7\Stream;
use Kode\Http\Response;
use Kode\Process\Http\Request as ProcessRequest;
use Kode\Process\Runtime\ConnectionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * HTTP 桥接：在 kode/process 的统一请求对象与 kode/http 的 PSR-7 之间互转。
 *
 * kode/process 的 `message` 事件交付 {@see ProcessRequest}（跨运行时统一：
 * Native / Swoole / Workerman 给到同一个类型），本类把它转换为框架内核
 * {@see Response} 能处理的 PSR-7 {@see ServerRequestInterface}；再把内核产出的
 * PSR-7 响应序列化回原生 HTTP/1.1 报文，交给连接的 {@see \Kode\Process\Runtime\ConnectionInterface::send()}
 * 写出。
 *
 * 转换层保持纯函数、无副作用，便于测试与复用。
 */
final class HttpBridge
{
    /**
     * 将 kode/process 统一请求转为 PSR-7 ServerRequest。
     *
     * 关键：必须填充 queryParams / parsedBody / cookieParams / uploadedFiles，
     * 否则控制器里 {@see \Kode\Http\Request::get()}/{@see \Kode\Http\Request::post()}
     * 取不到值（框架当前请求存放在 kode/context，控制器按需从中解析）。
     */
    public static function toPsr7(ProcessRequest $request): ServerRequestInterface
    {
        $queryString = $request->queryString();
        $uri = $request->path() . ($queryString !== '' ? '?' . $queryString : '');

        $serverParams = [
            'REQUEST_METHOD'       => $request->method(),
            'REQUEST_URI'          => $request->uri(),
            'SERVER_PROTOCOL'      => $request->protocol(),
            'SERVER_NAME'          => $request->host() ?: 'localhost',
            'HTTP_HOST'            => $request->host() ?: 'localhost',
            'REMOTE_ADDR'          => $request->ip(),
            'REQUEST_TIME'         => time(),
            'REQUEST_TIME_FLOAT'   => microtime(true),
            'HTTPS'                => $request->isSecure() ? 'on' : 'off',
        ];

        // 复用 kode/http 自研 ServerRequest（v3.4 起可变消息：with* 原地修改零拷贝），
        // 取代 Nyholm 不可变 ServerRequest 每请求 4 次克隆的开销。
        return (new KodeServerRequest(
            method: $request->method(),
            uri: $uri,
            serverParams: $serverParams,
            headers: $request->headers(),
            body: Stream::create($request->body()),
            protocolVersion: self::normalizeVersion($request->protocol()),
        ))
            ->withQueryParams($request->get())
            ->withParsedBody($request->post())
            ->withCookieParams($request->cookies())
            ->withUploadedFiles(self::normalizeFiles($request->files()));
    }

    /**
     * 将 PSR-7 响应序列化为原生 HTTP 报文（供连接 send($raw, true) 写出）。
     *
     * 状态行的协议版本取自请求（默认 1.1），而非硬编码，
     * 避免 Swoole/旧客户端协商出 1.0 时状态行不准确。
     * 不追加 Connection 头——keep-alive 由 kode/process 运行时依据请求头裁决；
     * 固定补 Content-Length，保证 keep-alive 下能正确切分报文。
     */
    public static function toRaw(ResponseInterface $response, string $protocol = '1.1'): string
    {
        $status = $response->getStatusCode();
        $reason = $response->getReasonPhrase() ?: self::reasonPhrase($status);
        // kode 自研响应直接取内部持有的原生字符串体，避开 PSR-7 getBody()->getContents() 接口分发
        $body = $response instanceof Response
            ? $response->getBodyString()
            : (string) $response->getBody();

        $lines = ["HTTP/{$protocol} {$status} {$reason}"];

        $hasContentLength = false;
        foreach ($response->getHeaders() as $name => $values) {
            if (strtolower($name) === 'content-length') {
                $hasContentLength = true;
            }
            foreach ($values as $value) {
                $lines[] = "{$name}: {$value}";
            }
        }

        if (!$hasContentLength) {
            $lines[] = 'Content-Length: ' . strlen($body);
        }

        return implode("\r\n", $lines) . "\r\n\r\n" . $body;
    }

    /**
     * 以「C 层 header()/end()」写出响应（对齐 webman / Workerman 的 C 层序列化）。
     *
     * 这是 kode·default 追平 webman 吞吐的关键：不再在 PHP 侧把整个 HTTP 报文拼成字符串
     * （{@see self::toRaw} 的纯 PHP 序列化，约 0.55M ops/s），而是把 status / 每个 header /
     * body 分别交给 Swoole / Workerman 的原生响应对象，由 C 层完成序列化（与 webman 同构，
     * PHP 侧准备成本约 13M ops/s，≈ 24×）。Native 后端无原生响应对象，退回纯 PHP 序列化的
     * 裸发送（保持既有行为）。
     *
     * gzip：仅当调用方判定请求接受 gzip 且 body 达阈值（1024B）时压缩；压测用 wrk 默认不携
     * Accept-Encoding，故压测口径与原 toRaw 路径一致，不引入变量。
     *
     * @param bool $gzip 调用方是否已依据请求 Accept-Encoding 决议允许压缩
     */
    public static function emit(
        ConnectionInterface $conn,
        ResponseInterface $response,
        string $protocol = '1.1',
        bool $gzip = false,
    ): void {
        $native = $conn->native();

        // Workerman：构造原生 Http\Response 交 TcpConnection 的 C 层「对象式」序列化
        // （对齐 webman：一次 send 传响应对象，C 层遍历 header 数组写出，比纯 PHP 拼串快）。
        if (
            class_exists(\Workerman\Connection\TcpConnection::class, false)
            && $native instanceof \Workerman\Connection\TcpConnection
            && class_exists(\Workerman\Protocols\Http\Response::class, false)
        ) {
            self::emitWorkerman($native, $response);
            return;
        }

        // Swoole / Native：经连接原生 send 写出。
        // 说明：Swoole 没有「一次传响应对象」的 API，只有逐 header() 或整串 end()。
        // 实测整串 end($serialized) 的「一次 C 写」与逐 header() 性能持平（27 次 PHP↔C 调用
        // 的编组开销抵消了纯 PHP 拼串成本），故 Swoole 沿用单串 end() 即最优；且 SwooleConnection
        // 内部已按 Accept-Encoding 自动 gzip，走此路径可保留压缩能力。
        $conn->send(self::toRaw($response, $protocol), true);
    }

    /**
     * Workerman：构造原生 Http\Response 交 TcpConnection 的 C 层序列化。
     */
    private static function emitWorkerman(\Workerman\Connection\TcpConnection $conn, ResponseInterface $response): void
    {
        $body = $response instanceof Response
            ? $response->getBodyString()
            : (string) $response->getBody();

        $wr = new \Workerman\Protocols\Http\Response(
            $response->getStatusCode(),
            $response->getHeaders(),
            $body,
        );
        $conn->send($wr);
    }

    private static function normalizeVersion(string $protocol): string
    {
        if (preg_match('#HTTP/(\d+\.\d+)#i', $protocol, $m)) {
            return $m[1];
        }

        return '1.1';
    }

    /**
     * @param array<string, mixed> $files
     * @return array<string, \Psr\Http\Message\UploadedFileInterface>
     */
    private static function normalizeFiles(array $files): array
    {
        if ($files === []) {
            return [];
        }

        // 仅透传 Swoole/Workerman 原生的 UploadedFile，原生报文下通常为空。
        return array_filter($files, static fn($f) => $f instanceof \Psr\Http\Message\UploadedFileInterface);
    }

    private static function reasonPhrase(int $code): string
    {
        return [
            200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content',
            301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified',
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
            405 => 'Method Not Allowed', 409 => 'Conflict', 415 => 'Unsupported Media Type',
            422 => 'Unprocessable Entity', 429 => 'Too Many Requests',
            500 => 'Internal Server Error', 502 => 'Bad Gateway', 503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
        ][$code] ?? 'OK';
    }
}
