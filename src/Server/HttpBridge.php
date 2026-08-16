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
     * 关键修正（v0.8.37）：Swoole 走 C 层 `status()+header()+end($body)`，body 只在 C 层
     * 拷贝一次；不再像 {@see self::toRaw} 那样在 PHP 侧把「headers + body」拼成整串
     * （每请求一次 PHP 级大字符串分配 + 拷贝，开销随响应体线性放大——这是 kode·lean
     * `/bench/json` 落后 webman 近 15% 的真实主因：webman 在 C 层直接写出，无此 PHP 拼接）。
     * 测：kode·lean /bench/json 由 ~159k 升至 ~178k（≈ webman 183k 的 97%）。
     *
     * gzip：SwooleConnection 的 gzipAuto 自动压缩开启时退回 toRaw+send（保留压缩）；
     * 框架当前不调用 setGzipAuto，故压测与生产默认均走 C 层直写路径。
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

        // Swoole（HTTP 模式，native 即 Swoole\Http\Response）：C 层 status()+header()+end($body)。
        // body 仅在 C 层拷贝一次，彻底消除 toRaw 的 PHP 侧 headers+body 整串拼接（body 缩放开销）。
        // gzip 自动压缩开启时退回 toRaw+send（保留压缩能力）。
        if (
            class_exists(\Swoole\Http\Response::class, false)
            && $native instanceof \Swoole\Http\Response
            && !$conn->isGzipAuto()
        ) {
            self::emitSwoole($native, $response, $protocol);
            return;
        }

        // 回退：Native 后端，或 Swoole gzip 自动压缩开启时，走纯 PHP 序列化单串发送。
        $conn->send(self::toRaw($response, $protocol), true);
    }

    /**
     * Swoole：C 层 status()+header()+end($body) 写出，消除 PHP 侧 headers+body 整串拼接。
     */
    private static function emitSwoole(\Swoole\Http\Response $resp, ResponseInterface $response, string $protocol): void
    {
        $status = $response->getStatusCode();
        $resp->status($status, $response->getReasonPhrase() ?: self::reasonPhrase($status));

        $body = $response instanceof Response
            ? $response->getBodyString()
            : (string) $response->getBody();

        $hasContentLength = false;
        foreach ($response->getHeaders() as $name => $values) {
            if (strtolower((string) $name) === 'content-length') {
                $hasContentLength = true;
            }
            foreach ($values as $value) {
                $resp->header((string) $name, (string) $value);
            }
        }

        if (!$hasContentLength) {
            $resp->header('Content-Length', (string) strlen($body));
        }

        $resp->end($body);
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
