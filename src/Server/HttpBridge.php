<?php

declare(strict_types=1);

namespace Kode\Framework\Server;

use Kode\Http\Response;
use Kode\Process\Http\Request as ProcessRequest;
use Nyholm\Psr7\ServerRequest;
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

        $psr = new ServerRequest(
            method: $request->method(),
            uri: $uri,
            headers: $request->headers(),
            body: $request->body(),
            version: self::normalizeVersion($request->protocol()),
            serverParams: $serverParams,
        );

        return $psr
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
        $body = (string) $response->getBody();

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
