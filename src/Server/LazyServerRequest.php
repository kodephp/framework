<?php

declare(strict_types=1);

namespace Kode\Framework\Server;

use Kode\Http\Psr7\Message\ServerRequest as KodeServerRequest;
use Kode\Http\Psr7\Stream;
use Kode\Process\Http\Request as ProcessRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * 懒解析服务端请求：在 kode/http 的 {@see KodeServerRequest} 之上，
 * 把「查询参数 / 解析后请求体 / Cookie / 上传文件」四类**昂贵解析**从
 * 每请求急切构造改为**首次访问才解析并缓存**。
 *
 * 背景：原 {@see HttpBridge::toPsr7} 每请求都急切调用
 * `ProcessRequest::get()/post()/cookies()/files()` 并 populate 进 PSR-7，
 * 但大多数热路径（如 /bench/json）的 handler 根本不读这些字段——
 * 路由匹配只消费 method + path 两个字符串（见 kode/http Router::match）。
 * 急切解析是纯浪费。本类把这些解析延迟到真正被读取时，使热路径零解析成本。
 *
 * 设计要点：
 * - 继承 KodeServerRequest，仅覆写 4 个重 getter + 其对应 with* setter，
 *   其余 25 个 PSR-7 方法全部继承，契约 100% 不变（仍是 ServerRequestInterface）。
 * - with* setter 同步更新基类与本地缓存，保证 RouteRunner::withAttribute 等
 *   继承行为不变、且显式设置后 get* 返回被设置值（不回退到原生解析）。
 * - 这是框架仓库内的纯优化，不触碰 kode/http 内核契约，也不需要 composer patch。
 */
final class LazyServerRequest extends KodeServerRequest implements ServerRequestInterface
{
    /** @var ProcessRequest 原生统一请求，懒解析的数据源 */
    private ProcessRequest $native;

    /** @var array|null 懒解析缓存：查询参数 */
    private ?array $lazyQuery = null;

    /** @var array|null 懒解析缓存：Cookie 参数 */
    private ?array $lazyCookie = null;

    /** @var mixed 懒解析缓存：解析后的请求体 */
    private mixed $lazyBody = null;

    /** @var array|null 懒解析缓存：上传文件 */
    private ?array $lazyFiles = null;

    /**
     * @param string $method HTTP 方法
     * @param UriInterface|string $uri 请求 URI（path?query 形态）
     * @param array $serverParams 服务器参数
     * @param array $headers 请求头
     * @param StreamInterface|null $body 请求体流
     * @param string $protocolVersion HTTP 协议版本
     */
    public function __construct(
        ProcessRequest $native,
        string $method,
        UriInterface|string $uri,
        array $serverParams = [],
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocolVersion = '1.1'
    ) {
        parent::__construct($method, $uri, $serverParams, $headers, $body, $protocolVersion);
        $this->native = $native;
    }

    /**
     * 首次访问才从原生请求解析查询参数并缓存。
     */
    public function getQueryParams(): array
    {
        return $this->lazyQuery ??= $this->native->get();
    }

    /**
     * 首次访问才从原生请求解析 Cookie 参数并缓存。
     */
    public function getCookieParams(): array
    {
        return $this->lazyCookie ??= $this->native->cookies();
    }

    /**
     * 首次访问才从原生请求解析请求体并缓存。
     */
    public function getParsedBody(): mixed
    {
        return $this->lazyBody ??= $this->native->post();
    }

    /**
     * 首次访问才从原生请求取上传文件并归一化、缓存。
     */
    public function getUploadedFiles(): array
    {
        return $this->lazyFiles ??= self::normalizeFiles($this->native->files());
    }

    public function withQueryParams(array $query): static
    {
        $this->lazyQuery = $query;

        return parent::withQueryParams($query);
    }

    public function withCookieParams(array $cookies): static
    {
        $this->lazyCookie = $cookies;

        return parent::withCookieParams($cookies);
    }

    public function withParsedBody(mixed $data): static
    {
        $this->lazyBody = $data;

        return parent::withParsedBody($data);
    }

    public function withUploadedFiles(array $uploadedFiles): static
    {
        $this->lazyFiles = $uploadedFiles;

        return parent::withUploadedFiles($uploadedFiles);
    }

    /**
     * 复用 HttpBridge 的归一化逻辑：仅透传 PSR-7 UploadedFile 实例。
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
