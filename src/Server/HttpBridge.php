<?php

declare(strict_types=1);

namespace Kode\Framework\Server;

use Kode\Http\Psr7\Message\ServerRequest as KodeServerRequest;
use Kode\Http\Psr7\Stream;
use Kode\Http\Response;
use Kode\Process\Http\Request as ProcessRequest;
use Kode\Process\Protocol\HttpProtocol;
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

        // 协议版本由驱动自身持有（SwooleConnection / Http2Stream / WorkermanConnection /
        // NativeConnection 各自已知其协议），此处仅从请求头形态（"HTTP/1.1"）提取 "1.1"。
        $protocol = $request->protocol();
        $protocolVersion = preg_match('#HTTP/(\d+\.\d+)#i', $protocol, $m) ? $m[1] : '1.1';

        // 不再急切调用 withQueryParams / withParsedBody / withCookieParams / withUploadedFiles：
        // 这四类解析对路由匹配（只消费 method + path 两个字符串）完全无用，且多数热路径
        // handler 根本不读它们（如 /bench/json）。改由 LazyServerRequest 在首次访问时才从
        // 原生 ProcessRequest 解析并缓存，使热路径零解析成本。契约不变（仍是 ServerRequestInterface）。
        // KODE_EAGER=1 时回退到急切旧路径（每请求解析全部字段），仅用于压测 A/B 对照，非生产路径。
        if (($GLOBALS['KODE_EAGER'] ?? ($_SERVER['KODE_EAGER'] ?? '0')) === '1') {
            return (new KodeServerRequest(
                method: $request->method(),
                uri: $uri,
                serverParams: $serverParams,
                headers: $request->headers(),
                body: Stream::create($request->body()),
                protocolVersion: $protocolVersion,
            ))
                ->withQueryParams($request->get())
                ->withParsedBody($request->post())
                ->withCookieParams($request->cookies())
                ->withUploadedFiles(self::normalizeFiles($request->files()));
        }

        return new LazyServerRequest(
            native: $request,
            method: $request->method(),
            uri: $uri,
            serverParams: $serverParams,
            headers: $request->headers(),
            body: Stream::create($request->body()),
            protocolVersion: $protocolVersion,
        );
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
     * 写出一个 PSR-7 响应。
     *
     * 委托给 kode/process 的 {@see ConnectionInterface::sendResponse()}，由各运行时驱动
     * 内部决定最优写出策略（Swoole C 层 status()+header()+end($body) / Workerman 原生
     * Http\Response 对象式 / Native 序列化为整串）。框架层完全不点名任何引擎类，
     * 符合「框架只做薄封装」的架构红线——引擎专用写出逻辑全部下沉在 kode/process 各自的
     * Driver 中（kode/process >= 5.2.31 原生提供 ConnectionInterface::sendResponse，
     * 引擎专用写出逻辑已随包交付，无需框架侧 patch）。
     *
     * 协议版本由驱动自身持有（SwooleConnection / Http2Stream / WorkermanConnection /
     * NativeConnection 各自已知其协议），gzip 自动压缩由驱动依据请求 Accept-Encoding
     * 内部裁决（autoGzip 默认 true）——均无需调用方传入，故 emit() 只透传响应对象。
     */
    public static function emit(
        ConnectionInterface $conn,
        ResponseInterface $response,
    ): void {
        // 优先走框架 rawBody 感知的快速路径：kode/http 响应体以原生字符串持有
        // （Resp::json → Response::make 直接缓存在 $rawBody），toRaw 经 getBodyString() 零拷贝取出，
        // 避免 kode/process Psr7Response::toHttp11 经 PSR-7 getBody() 对响应体做
        // 「StringStream 二次物化 + 销毁 rawBody 缓存」的逐请求分配（体越大越慢，是 /bench/json
        // 落后 webman 的主因）。输出与 toHttp11 逐字节一致。
        // 仅当连接层确会触发自动 gzip（请求带 Accept-Encoding: gzip 且响应体 ≥ GZIP_MIN_SIZE）时，
        // 才退回官方 sendResponse 以保留压缩能力；其余情况（含压测 wrk，不带该头）一律走快路径。
        $bodyLen = $response instanceof Response
            ? strlen($response->getBodyString())
            : strlen((string) $response->getBody());
        if (
            $bodyLen >= HttpProtocol::GZIP_MIN_SIZE
            && method_exists($conn, 'isGzipAuto')
            && $conn->isGzipAuto()
        ) {
            $conn->sendResponse($response);

            return;
        }

        $conn->send(HttpBridge::toRaw($response), true);
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

    /**
     * 仅透传 PSR-7 UploadedFile 实例（原生报文下通常为空）。
     * 急切旧路径（KODE_EAGER=1）使用；懒路径的等价逻辑见 LazyServerRequest。
     *
     * @param array<string, mixed> $files
     * @return array<string, \Psr\Http\Message\UploadedFileInterface>
     */
    private static function normalizeFiles(array $files): array
    {
        if ($files === []) {
            return [];
        }

        return array_filter($files, static fn($f) => $f instanceof \Psr\Http\Message\UploadedFileInterface);
    }
}
