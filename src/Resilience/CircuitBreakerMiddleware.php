<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience;

use Kode\Framework\Resilience\Events\CircuitOpened;
use Kode\Framework\Http\RouteMatchTrait;
use Kode\Framework\Http\RouteRegistry;
use Kode\Framework\Http\RouteResolver;
use Kode\Framework\Http\Support\RouteKey;
use Kode\Http\Response;
use Kode\Http\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HTTP 熔断中间件（薄壳层，复用框架 Breaker 注册表）。
 *
 * 把「故障隔离」能力接进 HTTP 流量：在边缘保护下游依赖，避免故障级联雪崩。
 * 与 RetryMiddleware（瞬态抖动恢复）、IdempotencyMiddleware（防重复提交）同属边缘韧性三件套。
 *
 * 设计要点：
 *  - 复用 {@see Breaker} 注册表（同一服务名与 `breaker()->run()` 共享状态），
 *    边缘失败也会让程序化熔断对该下游生效，反之亦然；
 *  - 只把「下游真实故障」计入熔断：响应状态码 >= `status_threshold`（默认 500）
 *    或 handler 抛出传输层异常（连接失败 / 超时）；
 *  - 4xx（客户端错误，下游本身健康）默认**不计失败**（`record_4xx_as_success=true`），
 *    避免被「上游用户传错参数」误熔断；
 *  - 熔断 OPEN 时直接短路返回 `open_status`（默认 503）降级响应，**连重试都不发起**
 *    （本中间件注册在 RetryMiddleware 外层），把「已知不可用」对调用方快速失败；
 *  - 健康 / 指标等路径经 `exclude` 跳过，不被熔断包裹；
 *  - 响应带 `X-Circuit-Breaker`（状态）与 `X-Circuit-Breaker-Name`（服务名）供可观测性；
 *  - 短路时派发 {@see CircuitOpened} 事件（可选 dispatcher），便于告警。
 *
 * 与 RetryMiddleware 的协作：熔断在外、重试在内。下游持续 5xx 时，重试耗尽后的最终
 * 5xx 才被本中间件记为一次失败；重试能救回的瞬态故障不会累积熔断，二者不打架。
 */
final class CircuitBreakerMiddleware implements MiddlewareInterface
{
    use RouteMatchTrait;

    private ?\Closure $dispatcher = null;

    /**
     * @param array<string, mixed>      $options    见 config/resilience.php 的 `breaker.http` 段
     * @param callable(object): object|null $dispatcher 事件派发器（默认 null = 不派发）
     */
    public function __construct(
        private readonly Breaker $breaker,
        private readonly array $options = [],
        ?callable $dispatcher = null,
        private readonly ?Router $router = null,
        private readonly ?RouteRegistry $registry = null,
        private readonly ?RouteResolver $resolver = null,
    ) {
        $this->dispatcher = $dispatcher === null ? null : \Closure::fromCallable($dispatcher);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (empty($this->options['enabled'] ?? true)) {
            return $handler->handle($request);
        }

        // 路由属性门控：未标记 #[CircuitBreaker] 的路由直接放行（O(1) 早退），
        // 仅保护显式声明路由，避免对无关请求做每次熔断器状态读写。
        // 匹配结果由 RouteResolver 在单次请求内缓存（首个中间件 match 一次，后续命中），
        // 避免每个路由感知型中间件各自全表重匹配。resolver/registry 为空（单测直构）则跳过门控。
        [$request, $matched] = $this->resolveRoute($request);
        if ($matched !== null && $matched->isFound() && $matched->route !== null
            && $this->registry !== null && !$this->registry->circuitBreakerOf($matched->route)) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();

        foreach ($this->excludePrefixes() as $prefix) {
            if ($prefix !== '' && str_starts_with($path, $prefix)) {
                // 健康 / 指标 / 静态资源等路径跳过熔断，避免误伤自愈与监控。
                return $handler->handle($request);
            }
        }

        $name = $this->resolveName($request);
        $cb = $this->breaker->get($name);

        if (!$cb->allowRequest()) {
            // 熔断已打开：直接短路降级，连重试都不发起（本中间件在重试外层）。
            $openStatus = (int) ($this->options['open_status'] ?? 503);
            $this->dispatch(new CircuitOpened($name, $cb->state(), $path, $openStatus));

            return $this->openResponse($openStatus, $name, $cb->state());
        }

        $threshold = (int) ($this->options['status_threshold'] ?? 500);
        $record4xx = (bool) ($this->options['record_4xx_as_success'] ?? true);

        try {
            $response = $handler->handle($request);
        } catch (\Throwable $e) {
            // 传输层异常（连接失败 / 超时 / 解析错误）：下游不可用，记为一次失败。
            $cb->recordFailure();
            throw $e;
        }

        $status = $response->getStatusCode();

        if ($status >= $threshold) {
            // 下游返回服务端错误：计入失败，逼近熔断阈值。
            $cb->recordFailure();
        } elseif ($record4xx || $status < 400) {
            // 2xx/3xx 或（默认）4xx：下游健康，记成功（重置失败计数）。
            $cb->recordSuccess();
        }
        // 否则（record_4xx_as_success=false 且 4xx）保持中性：既不记成功也不记失败。

        return $this->withStateHeader($response, $name, $cb->state());
    }

    private function resolveName(ServerRequestInterface $request): string
    {
        $mode = (string) ($this->options['derive_from'] ?? 'path');

        if ($mode === 'fixed') {
            return (string) ($this->options['service_name'] ?? 'http');
        }

        if ($mode === 'host') {
            $host = $request->getUri()->getHost();

            return $host === '' ? 'http' : $host;
        }

        // path（默认）：按路由隔离，不同路由独立熔断，互不影响。
        // v1.0.42（H8）：路径经 RouteKey 归一化（/users/42 → /users/{id}、UUID → /{uuid}），
        // 同一路由的动态参数实例共享同一熔断状态，防止遍历动态路径绕过熔断。
        return RouteKey::normalize($request->getUri()->getPath()) ?: '/';
    }

    private function openResponse(int $status, string $name, string $state): ResponseInterface
    {
        $body = (string) json_encode([
            'code' => $status,
            'msg' => 'circuit breaker open',
            'data' => ['breaker' => $name, 'state' => $state],
        ], JSON_UNESCAPED_UNICODE);

        $response = Response::make($body, $status, ['Content-Type' => 'application/json']);

        return $this->withStateHeader($response, $name, $state);
    }

    private function withStateHeader(ResponseInterface $response, string $name, string $state): ResponseInterface
    {
        return $response
            ->withHeader('X-Circuit-Breaker', $state)
            ->withHeader('X-Circuit-Breaker-Name', $name);
    }

    /**
     * @return array<int, string>
     */
    private function excludePrefixes(): array
    {
        $list = $this->options['exclude'] ?? ['/health', '/metrics', '/favicon.ico'];

        return array_map('strval', (array) $list);
    }

    private function dispatch(object $event): void
    {
        if ($this->dispatcher !== null) {
            ($this->dispatcher)($event);
        }
    }
}
