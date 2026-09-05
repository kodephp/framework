<?php

declare(strict_types=1);

namespace Kode\Framework\Server;

use Kode\Http\Psr7\Message\LazyHeaderAware;
use Kode\Http\Psr7\Message\ServerRequest as KodeServerRequest;
use Kode\Process\Http\Request as ProcessRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * 懒解析服务端请求：在 kode/http 的 {@see KodeServerRequest} 之上，
 * 把「查询参数 / 解析后请求体 / Cookie / 上传文件 / 请求头 / 原始请求体 / 服务器参数」
 * 七类昂贵解析从每请求急切构造改为**首次访问才解析并缓存**。
 *
 * 背景：{@see HttpBridge::toPsr7} 旧实现每请求急切调用
 * `ProcessRequest::get()/post()/cookies()/files()/headers()/body()`
 * 并 populate 进 PSR-7，但大多数热路径（如 /bench/json）的 handler 根本不读这些字段——
 * 路由匹配只消费 method + path 两个字符串（见 kode/http Router::match）。
 * 急切解析是纯浪费。本类把这七类解析全部延迟到真正被读取时，使热路径只付出
 * 「空构造 + 幂等加载」的成本。
 *
 * 设计要点：
 * - 继承 KodeServerRequest，仅覆写重 getter + 其对应 with* setter，
 *   其余 PSR-7 方法全部继承，契约 100% 不变（仍是 ServerRequestInterface）。
 * - with* setter 同步更新基类与本地缓存，保证 RouteRunner::withAttribute 等
 *   继承行为不变、且显式设置后 get* 返回被设置值（不回退到原生解析）。
 * - 构造参数向后兼容：默认空容器/空流 = 「待懒解析」；传入非空值 = 「显式采用」
 *   （旧调用路径行为不变）。
 * - 这是框架仓库内的纯优化，不触碰 kode/http 内核契约，也不需要 composer patch。
 */
final class LazyServerRequest extends KodeServerRequest implements ServerRequestInterface, LazyHeaderAware
{
    /** @var ProcessRequest 原生统一请求，懒解析的数据源 */
    private ProcessRequest $native;

    /** @var array|null 懒解析缓存：查询参数 */
    private ?array $lazyQuery = null;

    /** @var array|null 懒解析缓存：Cookie 参数 */
    private ?array $lazyCookie = null;

    /** @var mixed 懒解析缓存：解析后的请求体 */
    private mixed $lazyBody = null;

    /** @var bool 解析后请求体是否已解析（null 值也需标记） */
    private bool $bodyParsed = false;

    /** @var array|null 懒解析缓存：上传文件 */
    private ?array $lazyFiles = null;

    /** @var array|null 懒解析缓存：服务器参数 */
    private ?array $lazyServerParams = null;

    /** @var bool 请求头是否已从原生解析进基类 */
    private bool $headersResolved = false;

    /**
     * 实现 {@see LazyHeaderAware}：在不触发任何解析的前提下暴露 header 解析状态，
     * 供 kode/http 链路追踪嗅探（hasTraceHeaders）等热路径守卫做早退判断，
     * 避免强制 server params 构建 / header 规范化。
     */
    public function isHeadersResolved(): bool
    {
        return $this->headersResolved;
    }

    /**
     * 定向读取单个 header，不触发全量解析：
     * - 未解析：委托原生请求的 rawHeader()——RAW 源对原始报文做一次 stripos
     *   定向扫描，其它源退化为哈希查找，均不会触发本类的 resolveHeaders()
     *   （header 规范化）或 getServerParams()（引导构建）；
     * - 已解析：退化为普通 getHeaderLine。
     */
    public function peekHeader(string $name): ?string
    {
        if (!$this->headersResolved) {
            $value = $this->native->rawHeader($name);

            return $value !== '' ? $value : null;
        }

        $line = parent::getHeaderLine($name);

        return $line !== '' ? $line : null;
    }

    /** @var bool 原始请求体是否已从原生读取 */
    private bool $rawBodyResolved = false;

    /** @var string|null 懒惰协议版本缓存（getProtocolVersion() 首次访问才解析一次） */
    private ?string $lazyProtocolVersion = null;

    /** @var bool 构造时是否显式传入服务器参数（跳过懒解析） */
    private bool $hasExplicitServerParams;

    /** @var bool 构造时是否显式传入请求头（跳过懒解析） */
    private bool $hasExplicitHeaders;

    /** @var bool 构造时是否显式传入请求体（跳过懒解析） */
    private bool $hasExplicitBody;

    /**
     * @param string $method HTTP 方法
     * @param UriInterface|string $uri 请求 URI（path?query 形态）
     * @param array $serverParams 服务器参数；传 [] 表示首次访问时懒解析
     * @param array $headers 请求头；传 [] 表示首次访问时懒解析
     * @param StreamInterface|null $body 请求体流；传 null 表示首次访问时懒解析
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
        $this->hasExplicitServerParams = $serverParams !== [];
        $this->hasExplicitHeaders      = $headers !== [];
        $this->hasExplicitBody         = $body !== null;

        // 协议版本懒化：默认 '1.1' 的常见热路径（HTTP/1.1 请求）不缓存任何值，
        // 交给 getProtocolVersion() 首次访问时从 native 精确提取；
        // 显式传入非默认版本（如 EAGER 分支提取到 HTTP/1.0）则直接采用。
        $this->lazyProtocolVersion = $protocolVersion !== '1.1' ? $protocolVersion : null;

        parent::__construct($method, $uri, $serverParams, $headers, $body, $this->lazyProtocolVersion ?? '1.1');
        $this->native = $native;
    }

    /**
     * 服务器参数：首次访问才从原生请求构建（显式传入则原样保留）。
     */
    public function getServerParams(): array
    {
        if ($this->lazyServerParams !== null) {
            return $this->lazyServerParams;
        }
        if ($this->hasExplicitServerParams) {
            return $this->lazyServerParams = parent::getServerParams();
        }

        $native = $this->native;
        // host() 只调一次：原生实现可能涉及 header 查找，多次调用纯浪费。
        $host = $native->host() ?: 'localhost';

        return $this->lazyServerParams = [
            'REQUEST_METHOD'     => $native->method(),
            'REQUEST_URI'        => $native->uri(),
            'SERVER_PROTOCOL'    => $native->protocol(),
            'SERVER_NAME'        => $host,
            'HTTP_HOST'          => $host,
            'REMOTE_ADDR'        => $native->ip(),
            'REQUEST_TIME'       => time(),
            'REQUEST_TIME_FLOAT' => microtime(true),
            'HTTPS'              => $native->isSecure() ? 'on' : 'off',
        ];
    }

    /**
     * 协议版本：首次访问才从原生请求解析并缓存。
     *
     * 热路径（路由匹配 + 响应物化）不消费协议版本，构造时不再急切提取
     * （HttpBridge::toPsr7 懒分支因此省去 protocol() 调用 + 正则）。仅在
     * 业务/中间件真正调用 {@see getProtocolVersion()} 时才付出解析成本，
     * 语义与旧实现（构造期提取）完全一致。
     */
    public function getProtocolVersion(): string
    {
        if ($this->lazyProtocolVersion === null) {
            $protocol = $this->native->protocol();

            $this->lazyProtocolVersion = preg_match('#HTTP/(\d+\.\d+)#i', $protocol, $m) ? $m[1] : '1.1';
        }

        return $this->lazyProtocolVersion;
    }

    /**
     * 与基类（可变风格）保持一致：原地更新协议版本并同步懒解析缓存，
     * 避免 with 之后 getProtocolVersion() 读到旧缓存值。
     */
    public function withProtocolVersion(string $version): static
    {
        parent::withProtocolVersion($version);
        $this->lazyProtocolVersion = $version;

        return $this;
    }

    public function getQueryParams(): array
    {
        return $this->lazyQuery ??= $this->native->get();
    }

    public function getCookieParams(): array
    {
        return $this->lazyCookie ??= $this->native->cookies();
    }

    public function getParsedBody(): mixed
    {
        if (!$this->bodyParsed) {
            $this->bodyParsed = true;
            $this->lazyBody = $this->native->post();
        }

        return $this->lazyBody;
    }

    public function getUploadedFiles(): array
    {
        return $this->lazyFiles ??= $this->normalizeFiles($this->native->files());
    }

    /**
     * 请求头：首次访问才从原生解析进基类（protected 字段可直接写）。
     */
    public function getHeaders(): array
    {
        $this->resolveHeaders();

        return parent::getHeaders();
    }

    public function hasHeader(string $name): bool
    {
        $this->resolveHeaders();

        return parent::hasHeader($name);
    }

    public function getHeader(string $name): array
    {
        $this->resolveHeaders();

        return parent::getHeader($name);
    }

    public function getHeaderLine(string $name): string
    {
        $this->resolveHeaders();

        return parent::getHeaderLine($name);
    }

    public function withHeader(string $name, $value): static
    {
        $this->resolveHeaders();

        return parent::withHeader($name, $value);
    }

    public function withAddedHeader(string $name, $value): static
    {
        $this->resolveHeaders();

        return parent::withAddedHeader($name, $value);
    }

    public function withoutHeader(string $name): static
    {
        $this->resolveHeaders();

        return parent::withoutHeader($name);
    }

    /**
     * 原始请求体：首次访问才从原生读取（显式传入的流原样保留）。
     */
    public function getBody(): StreamInterface
    {
        if (!$this->rawBodyResolved) {
            $this->rawBodyResolved = true;
            if (!$this->hasExplicitBody) {
                $this->rawBody = (string) $this->native->body();
            }
        }

        return parent::getBody();
    }

    public function withBody(StreamInterface $body): static
    {
        $this->rawBodyResolved = true;
        $this->hasExplicitBody = true;

        return parent::withBody($body);
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
        $this->bodyParsed = true;

        return parent::withParsedBody($data);
    }

    public function withUploadedFiles(array $uploadedFiles): static
    {
        $this->lazyFiles = $uploadedFiles;

        return parent::withUploadedFiles($uploadedFiles);
    }

    /**
     * 首次访问才把原生请求头写入基类 headers/headerNames（protected 字段直写）。
     */
    private function resolveHeaders(): void
    {
        if ($this->headersResolved) {
            return;
        }
        $this->headersResolved = true;
        if ($this->hasExplicitHeaders) {
            return;
        }
        $headers = $this->native->headers();
        if ($headers === []) {
            return;
        }
        // 与 KodeServerRequest::initializeHeaders 等价写法：直接写 protected 字段，
        // 使 parent::hasHeader/getHeader/getHeaders 基于真实数据工作。
        foreach ($headers as $name => $value) {
            $normalized = strtolower($name);
            $this->headers[$name] = is_array($value) ? $value : [$value];
            $this->headerNames[$normalized] = $name;
        }
    }

    /**
     * 把原生上传文件数组归一化为 PSR-7 UploadedFile 列表（只留接口实例）。
     *
     * @return array<string, \Psr\Http\Message\UploadedFileInterface>
     */
    private function normalizeFiles(array $files): array
    {
        if ($files === []) {
            return [];
        }

        return array_filter($files, static fn($f) => $f instanceof \Psr\Http\Message\UploadedFileInterface);
    }
}