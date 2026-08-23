<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Middleware;

use Kode\Framework\Http\RateLimit\Algorithm;
use Kode\Framework\Http\RateLimit\LimiterFactory;
use Kode\Framework\Http\Resp;
use Kode\Framework\Http\RouteMatchTrait;
use Kode\Framework\Http\RouteRegistry;
use Kode\Framework\Http\RouteResolver;
use Kode\Framework\Http\Support\RouteKey;
use Kode\Framework\Http\Support\TrustedProxies;
use Kode\Http\Routing\Router;
use Kode\Limiting\Attribute\RateLimit;
use Kode\Limiting\Enum\LimiterType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 限流中间件（薄适配 kode/limiting）
 *
 * 作为**全局中间件**运行，对每条路由统一限流：
 *  - 若路由上声明了 #[RateLimit]（类级 / 方法级，可多条叠加），逐条应用对应规则；
 *  - 否则回落到全局默认限流（config/limiting.php 的 capacity/rate/algorithm）。
 *
 * 限流规则即 kode/limiting 的 {@see RateLimit} 属性，限流算法与「规则对象」由该包提供；
 * 本中间件只做「按路由查表 → 调 LimiterFactory 取 Limiter → 消费额度 → 落响应头」的编排，
 * 不再在框架里重复定义限流规则结构。
 *
 * 限流状态存于「框架统一存储」：driver=memory 为单进程内存；改为 redis 即变为
 * 分布式（跨进程 / 跨机共享限额），支持 standalone / sentinel / cluster 三种部署。
 *
 * 超限返回 429，并附带标准限流响应头（X-RateLimit-Limit / Remaining / Reset、
 * Retry-After），遵循 IETF 草案。限流总开关见 config/limiting.enabled。
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    use RouteMatchTrait;

    /**
     * @param array<string, mixed> $globalConfig 框架 config/limiting.php 全量配置
     * @param array<int, string>   $trusted     受信代理列表（IP / CIDR / '*'），见 config/security.php
     */
    public function __construct(
        private readonly Router $router,
        private readonly RouteRegistry $registry,
        private readonly LimiterFactory $factory,
        private readonly array $globalConfig = [],
        private readonly ?RouteResolver $resolver = null,
        private readonly array $trusted = [],
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (empty($this->globalConfig['enabled'] ?? true)) {
            return $handler->handle($request);
        }

        [$request, $matched] = $this->resolveRoute($request);
        $path = $request->getUri()->getPath();

        $rules = [];
        if ($matched !== null && $matched->isFound() && $matched->route !== null) {
            $rules = $this->registry->rateLimitsOf($matched->route);
        }

        // 无声明式规则：仅在全局兜底限流开启时回落，否则直接放行
        //（限流只作用于 #[RateLimit] 标记的路由，避免无意识地把整站压到极低额度）。
        if ($rules === []) {
            return $this->applyGlobal($request, $handler);
        }

        // 应用每条声明式规则（同分组可叠加多条，任一拒绝即 429）。
        // 客户端 IP 只解析一次：keyContext 与 fallback 键共用（热路径省一次 REMOTE_ADDR 读取 + 受信判定）。
        $headers = [];
        $denied = null;
        $ip = $this->clientIp($request);
        $context = $this->keyContext($matched?->params ?? [], $ip);
        $fallback = $this->routeKey($request) . ':' . $ip;

        foreach ($rules as $rule) {
            $limiter = $this->factory->make($rule);
            $key = $rule->resolveKey($context, $fallback);
            $result = $limiter->consume($key, $rule->tokens);
            $headers = [...$headers, ...$result->toHeaders()];

            if ($result->isDenied() && $denied === null) {
                $denied = $result;
            }
        }

        if ($denied !== null) {
            return $this->tooMany($denied->toHeaders());
        }

        $response = $handler->handle($request);
        foreach ($headers as $header => $value) {
            $response = $response->withHeader($header, $value);
        }

        return $response;
    }

    /**
     * 全局默认限流：按「路由模板 + 客户端 IP」维度，单一限额。
     */
    private function applyGlobal(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 全局兜底限流默认关闭：未声明 #[RateLimit] 的路由直接放行，
        // 避免无意识地把整站限到极低额度（原默认 capacity=10/s 会真实限流生产流量）。
        $global = $this->globalConfig['global'] ?? [];
        if (empty($global['enabled'])) {
            return $handler->handle($request);
        }

        $key = 'rl:' . $this->routeKey($request) . ':' . $this->clientIp($request);
        $rule = $this->defaultRule($global);
        $limiter = $this->factory->make($rule);

        $result = $limiter->consume($key, 1);

        if ($result->isDenied()) {
            return $this->tooMany($result->toHeaders());
        }

        $response = $handler->handle($request);
        foreach ($result->toHeaders() as $header => $value) {
            $response = $response->withHeader($header, $value);
        }

        return $response;
    }

    /**
     * 由全局配置构造默认 #[RateLimit] 规则（无 key 模板，由中间件按「路由 + 客户端 IP」推导）。
     *
     * @param array<string, mixed> $config
     */
    private function defaultRule(array $config): RateLimit
    {
        return new RateLimit(
            capacity: (int) ($config['capacity'] ?? 1000),
            rate: (float) ($config['rate'] ?? 1.0),
            type: Algorithm::fromName((string) ($config['algorithm'] ?? 'token_bucket')),
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function tooMany(array $headers): ResponseInterface
    {
        $response = Resp::error('请求过于频繁，请稍后再试', 429, [
            'retry_after' => (int) ($headers['Retry-After'] ?? 0),
        ]);

        foreach ($headers as $header => $value) {
            $response = $response->withHeader($header, $value);
        }

        return $response;
    }

    /**
     * 限流键上下文：路由参数（{id} 等）+ 客户端 IP，供 #[RateLimit] 的 key 模板渲染。
     *
     * @param array<string, string> $routeParams
     * @param string                $ip 已解析的真实客户端 IP（调用方已算好，禁止再调 clientIp）
     * @return array<string, string|int>
     */
    private function keyContext(array $routeParams, string $ip): array
    {
        $context = $routeParams;
        $context['ip'] = $ip;

        return $context;
    }

    /**
     * 把路由参数（/users/42）归一为模板（/users/{id}）以共享限额。
     */
    private function routeKey(ServerRequestInterface $request): string
    {
        return RouteKey::normalize($request->getUri()->getPath());
    }

    /**
     * 取真实客户端 IP：仅当直连对端（REMOTE_ADDR）为受信代理时才采信转发头，
     * 否则一律用 REMOTE_ADDR——杜绝伪造 X-Forwarded-For 绕过限流（H4）。
     */
    private function clientIp(ServerRequestInterface $request): string
    {
        return TrustedProxies::clientIp($request, $this->trusted);
    }
}
