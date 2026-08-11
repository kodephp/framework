<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Middleware;

use Kode\Framework\Http\RateLimit\LimiterFactory;
use Kode\Framework\Http\RateLimit\RateLimitRule;
use Kode\Framework\Http\Resp;
use Kode\Framework\Http\RouteRegistry;
use Kode\Http\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 限流中间件（kode/limiting）
 *
 * 作为**全局中间件**运行，对每条路由统一限流：
 *  - 若路由上声明了 #[RateLimit]（类级 / 方法级，可多条叠加），逐条应用对应规则；
 *  - 否则回落到全局默认限流（config/limiting.php 的 capacity/rate/algorithm）。
 *
 * 限流状态存于「框架统一存储」：driver=memory 为单进程内存；改为 redis 即变为
 * 分布式（跨进程 / 跨机共享限额），支持 standalone / sentinel / cluster 三种部署。
 *
 * 超限返回 429，并附带标准限流响应头（X-RateLimit-Limit / Remaining / Reset、
 * Retry-After），遵循 IETF 草案。限流总开关见 config/limiting.enabled。
 *
 * 演示 PHP 8.3 新特性：#[\Override] 显式标注对接口方法的重写。
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, mixed> $globalConfig 框架 config/limiting.php 全量配置
     */
    public function __construct(
        private readonly Router $router,
        private readonly RouteRegistry $registry,
        private readonly LimiterFactory $factory,
        private readonly array $globalConfig = [],
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (empty($this->globalConfig['enabled'] ?? true)) {
            return $handler->handle($request);
        }

        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        $rules = [];
        $matched = $this->router->match($method, $path);
        if ($matched->isFound() && $matched->route !== null) {
            $rules = $this->registry->rateLimitsOf($matched->route);
        }

        // 无声明式规则：回落全局默认限流（原行为）。
        if ($rules === []) {
            return $this->applyGlobal($request, $handler);
        }

        // 应用每条声明式规则（同分组可叠加多条，任一拒绝即 429）。
        $headers = [];
        $denied = null;
        $context = $this->keyContext($request, $matched?->params ?? []);
        $fallback = $this->routeKey($request) . ':' . $this->clientIp($request);

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
        $key = 'rl:' . $this->routeKey($request) . ':' . $this->clientIp($request);
        $rule = RateLimitRule::fromConfig($this->globalConfig);
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
     * @param array<string, string> $headers
     */
    private function tooMany(array $headers): ResponseInterface
    {
        $response = Resp::fail('请求过于频繁，请稍后再试', 'E429', 429);

        foreach ($headers as $header => $value) {
            $response = $response->withHeader($header, $value);
        }

        return $response;
    }

    /**
     * 限流键上下文：路由参数（{id} 等）+ 客户端 IP，供 #[RateLimit] 的 key 模板渲染。
     *
     * @param array<string, string> $routeParams
     * @return array<string, string|int>
     */
    private function keyContext(ServerRequestInterface $request, array $routeParams): array
    {
        $context = $routeParams;
        $context['ip'] = $this->clientIp($request);

        return $context;
    }

    /**
     * 把路由参数（/users/42）归一为模板（/users/{id}）以共享限额。
     */
    private function routeKey(ServerRequestInterface $request): string
    {
        $path = $request->getUri()->getPath();

        return (string) preg_replace(
            '/\/\d+(?=\/|$)/',
            '/{id}',
            preg_replace('/\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '/{uuid}', $path)
        );
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        $forwarded = $request->getHeaderLine('x-forwarded-for');
        if ($forwarded !== '') {
            return explode(',', $forwarded)[0];
        }

        return $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
    }
}
