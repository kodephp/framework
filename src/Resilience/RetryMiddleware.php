<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience;

use Kode\Framework\Http\RouteMatchTrait;
use Kode\Framework\Http\RouteRegistry;
use Kode\Framework\Http\RouteResolver;
use Kode\Http\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HTTP 重试中间件（薄壳层，复用 Retry 原语）。
 *
 * 把瞬态故障恢复能力接进 HTTP 流量：对「安全方法（默认 GET/HEAD/PUT/DELETE/OPTIONS）」
 * 的失败响应（默认 502/503/504）或指定异常类型按退避自动重试，把上游抖动对调用方屏蔽。
 * 与熔断器（保护下游雪崩）互补、与幂等中间件（防重复提交）配合。
 *
 * 设计要点：
 *  - 仅对「幂等 / 安全方法」重试（POST 默认不重试，避免副作用被重复触发）；
 *  - 响应维度：命中 `retry_on_status` 即重试（上游网关瞬态）；
 *  - 异常维度：命中 `retry_on_exception` 即重试（如自定义 UpstreamUnavailableException）；
 *  - 其它异常（4xx / 校验失败等）原本就不应重试 → 还原原始异常透传给 ExceptionMiddleware；
 *  - 全部重试耗尽且最终是上游 5xx → 原样返回最后一次响应（best-effort），不静默吞错；
 *  - 退避策略复用 config/resilience.php 的 `retry` 段（由 ResilienceServiceProvider 注入 Retry 单例），
 *    本中间件零新增外部依赖。
 *
 * 与 IdempotencyMiddleware 的协作：幂等中间件注册在外层，重放请求不会进入本中间件
 * （直接返回缓存响应），因此仅「首次真实执行」会被重试包裹，不会与重放逻辑打架。
 */
final class RetryMiddleware implements MiddlewareInterface
{
    use RouteMatchTrait;

    /**
     * @param array<string, mixed> $options 见 config/resilience.php 的 `retry.http` 段
     */
    public function __construct(
        private readonly Retry $retry,
        private readonly array $options = [],
        private readonly ?Router $router = null,
        private readonly ?RouteRegistry $registry = null,
        private readonly ?RouteResolver $resolver = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (empty($this->options['enabled'] ?? true)) {
            return $handler->handle($request);
        }

        // 路由属性门控：未标记 #[Retry] 的路由直接放行（O(1) 早退），
        // 仅包裹显式声明路由，避免对无关请求（如 /ping）做重试闭包包裹。
        // 匹配由 RouteResolver 在单次请求内缓存（首个中间件 match 一次，后续命中）。
        [$request, $matched] = $this->resolveRoute($request);
        if ($matched !== null && $matched->isFound() && $matched->route !== null
            && $this->registry !== null && !$this->registry->retryOf($matched->route)) {
            return $handler->handle($request);
        }

        $methods = (array) ($this->options['methods'] ?? ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS']);
        if (!in_array(strtoupper($request->getMethod()), $methods, true)) {
            // 非安全方法（如 POST）默认不重试，避免副作用重复触发。
            return $handler->handle($request);
        }

        $retryOnStatus = (array) ($this->options['retry_on_status'] ?? [502, 503, 504]);
        $retryOnException = (array) ($this->options['retry_on_exception'] ?? []);
        $attempts = max(1, (int) ($this->options['attempts'] ?? 3));
        $timeout = $this->options['timeout'] ?? null;
        $label = (string) ($this->options['label'] ?? ('http:' . $request->getMethod() . ' ' . $request->getUri()->getPath()));

        $run = function () use ($handler, $request, $retryOnStatus): ResponseInterface {
            $response = $handler->handle($request);
            if (in_array($response->getStatusCode(), $retryOnStatus, true)) {
                throw new RetryableHttpStatusException($response);
            }

            return $response;
        };

        $retryOn = function (\Throwable $e) use ($retryOnException): bool {
            if ($e instanceof RetryableHttpStatusException) {
                return true;
            }
            foreach ($retryOnException as $class) {
                if ($e instanceof $class) {
                    return true;
                }
            }

            return false;
        };

        try {
            return $this->retry->run($run, [
                'attempts' => $attempts,
                'timeout' => $timeout,
                'retryOn' => $retryOn,
                'label' => $label,
            ]);
        } catch (RetryExhausted $ex) {
            $last = $ex->last();
            if ($last instanceof RetryableHttpStatusException) {
                // 上游持续 5xx：原样返回最后一次响应（best-effort），不静默吞错、也不伪造成功。
                return $last->response();
            }

            // 非重试集内的异常（4xx / 校验失败等）：还原原始异常，交给 ExceptionMiddleware 统一处理。
            if ($last instanceof \Throwable) {
                throw $last;
            }

            throw $ex;
        }
    }
}
