<?php

declare(strict_types=1);

namespace Kode\Framework\Http;

use Kode\Framework\ApiDoc\Attributes\OpenApi;
use Kode\Limiting\Attribute\RateLimit;
use Kode\Http\Routing\Route;

/**
 * 路由来源登记表
 *
 * 框架在加载路由文件（app/routes.php、插件 routes.php、config 声明的额外来源）
 * 时，为每条新注册的路由打上「来源标签」，便于 `route:list` 命令按来源/文件
 * 聚合展示，也支持插件（独立模块）路由的可读性。
 *
 * 同时登记每条路由上的声明式限流规则（#[RateLimit] 属性），供全局限流中间件
 * 在请求期按路由查表、应用细粒度（且可分布式）的限流策略。
 *
 * 用 spl_object_id 作键，零侵入（不修改 kode/http 的 Route 对象）。
 */
final class RouteRegistry
{
    /** @var array<int, string> spl_object_id(Route) => 来源标签 */
    private array $sources = [];

    /** @var array<int, list<RateLimit>> spl_object_id(Route) => 限流规则 */
    private array $rateLimits = [];

    /** @var array<int, OpenApi> spl_object_id(Route) => OpenAPI 补充片段（属性路由才会写入） */
    private array $openApi = [];

    /** @var array<int, bool> spl_object_id(Route) => 是否启用 CSRF 防护（#[Csrf] 标记） */
    private array $csrf = [];

    /** @var array<int, bool> spl_object_id(Route) => 是否启用边缘熔断（#[CircuitBreaker] 标记） */
    private array $circuitBreakers = [];

    /** @var array<int, bool> spl_object_id(Route) => 是否启用 HTTP 重试（#[Retry] 标记） */
    private array $retries = [];

    /** @var array<int, bool> spl_object_id(Route) => 是否启用幂等防护（#[Idempotency] 标记） */
    private array $idempotencies = [];

    /**
     * 为一条路由标记来源。
     */
    public function tag(Route $route, string $source): void
    {
        $this->sources[spl_object_id($route)] = $source;
    }

    /**
     * 为一条路由登记限流规则（kode/limiting 的 #[RateLimit] 属性，类级 + 方法级叠加后的结果）。
     *
     * @param list<RateLimit> $rules
     */
    public function tagRateLimits(Route $route, array $rules): void
    {
        if ($rules !== []) {
            $this->rateLimits[spl_object_id($route)] = $rules;
        }
    }

    /**
     * 查询某条路由的来源标签（未知则返回 null）。
     */
    public function sourceOf(Route $route): ?string
    {
        return $this->sources[spl_object_id($route)] ?? null;
    }

    /**
     * 查询某条路由上的限流规则（无则返回空数组）。
     *
     * @return list<RateLimit>
     */
    public function rateLimitsOf(Route $route): array
    {
        return $this->rateLimits[spl_object_id($route)] ?? [];
    }

    /**
     * 为一条路由登记 OpenAPI 补充片段（来自控制器方法上的 #[OpenApi] 属性）。
     */
    public function tagOpenApi(Route $route, OpenApi $meta): void
    {
        $this->openApi[spl_object_id($route)] = $meta;
    }

    /**
     * 查询某条路由上的 OpenAPI 补充片段（无标注则返回 null）。
     */
    public function openApiOf(Route $route): ?OpenApi
    {
        return $this->openApi[spl_object_id($route)] ?? null;
    }

    /**
     * 为一条路由登记 CSRF 防护标记（来自控制器类/方法上的 #[Csrf] 属性）。
     */
    public function tagCsrf(Route $route, bool $flag = true): void
    {
        $this->csrf[spl_object_id($route)] = $flag;
    }

    /**
     * 查询某条路由是否启用 CSRF 防护（未标记则返回 false）。
     */
    public function csrfOf(Route $route): bool
    {
        return $this->csrf[spl_object_id($route)] ?? false;
    }

    /**
     * 为一条路由登记边缘熔断标记（来自控制器类/方法上的 #[CircuitBreaker] 属性）。
     */
    public function tagCircuitBreaker(Route $route, bool $flag = true): void
    {
        $this->circuitBreakers[spl_object_id($route)] = $flag;
    }

    /**
     * 查询某条路由是否启用边缘熔断（未标记则返回 false）。
     */
    public function circuitBreakerOf(Route $route): bool
    {
        return $this->circuitBreakers[spl_object_id($route)] ?? false;
    }

    /**
     * 为一条路由登记 HTTP 重试标记（来自控制器类/方法上的 #[Retry] 属性）。
     */
    public function tagRetry(Route $route, bool $flag = true): void
    {
        $this->retries[spl_object_id($route)] = $flag;
    }

    /**
     * 查询某条路由是否启用 HTTP 重试（未标记则返回 false）。
     */
    public function retryOf(Route $route): bool
    {
        return $this->retries[spl_object_id($route)] ?? false;
    }

    /**
     * 为一条路由登记幂等防护标记（来自控制器类/方法上的 #[Idempotency] 属性）。
     */
    public function tagIdempotency(Route $route, bool $flag = true): void
    {
        $this->idempotencies[spl_object_id($route)] = $flag;
    }

    /**
     * 查询某条路由是否启用幂等防护（未标记则返回 false）。
     */
    public function idempotencyOf(Route $route): bool
    {
        return $this->idempotencies[spl_object_id($route)] ?? false;
    }

    /**
     * 全部来源标签（spl_object_id => label）。
     *
     * @return array<int, string>
     */
    public function all(): array
    {
        return $this->sources;
    }
}
