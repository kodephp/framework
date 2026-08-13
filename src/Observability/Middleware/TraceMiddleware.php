<?php

declare(strict_types=1);

namespace Kode\Framework\Observability\Middleware;

use Kode\Framework\Observability\Trace\Span;
use Kode\Framework\Observability\Trace\SpanKind;
use Kode\Framework\Observability\Trace\SpanStatus;
use Kode\Framework\Observability\Trace\TraceContext;
use Kode\Framework\Observability\Trace\Tracer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 链路追踪中间件（全局最外层）。
 *
 *  - 请求进入：调用 {@see TraceContext::ensure()} 确定/复用 trace_id 与 span_id，
 *    并回写 $_SERVER 桥接 kode/exception 的异常 tracer。
 *  - 开根 span：若 tracing 启用，开启一个 SERVER 跨度覆盖整条请求（含下游中间件与处理），
 *    其 span_id 与响应 traceparent 一致，子调用通过 tracer()->start() 自然嵌套。
 *  - 响应返回：为**每一个**响应（含异常响应）追加 W3C traceparent 与 X-Trace-Id /
 *    X-Span-Id 头，保证整条调用链在网关 / 日志 / APM 中可串联。
 *
 * 放在全局最外层（prepend），无论下游是否抛异常都能附加链路头。
 */
final class TraceMiddleware implements MiddlewareInterface
{
    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 链路上下文建立失败绝不应阻断请求——降级为「无链路头」继续处理。
        try {
            TraceContext::ensure($request);
        } catch (\Throwable $e) {
            $this->log($e);
        }

        $root = null;
        $tracer = $this->resolveTracer();
        if ($tracer !== null && $tracer->isEnabled()) {
            $root = $tracer->start(
                $request->getMethod() . ' ' . $request->getUri()->getPath(),
                [
                    'http.method' => $request->getMethod(),
                    'http.route' => $request->getUri()->getPath(),
                    'http.scheme' => $request->getUri()->getScheme(),
                    'http.host' => $request->getUri()->getAuthority(),
                ],
                SpanKind::SERVER,
            );
        }

        try {
            $response = $handler->handle($request);
        } catch (\Throwable $e) {
            if ($root !== null) {
                $root->recordException($e);
                $tracer->end($root, SpanStatus::ERROR, $e->getMessage());
            }
            throw $e;
        }

        if ($root !== null) {
            $status = $response->getStatusCode() >= 500 ? SpanStatus::ERROR : SpanStatus::OK;
            $tracer->end($root, $status, $response->getReasonPhrase());
        }

        // 尽力附加链路头；任一头构造失败只跳过该头，不影响主响应。
        try {
            foreach (TraceContext::responseHeaders() as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
        } catch (\Throwable) {
            // best-effort：链路头缺失不影响业务响应。
        }

        return $response;
    }

    private function resolveTracer(): ?Tracer
    {
        try {
            if (app() === null || !app()->container->bound(Tracer::class)) {
                return null;
            }

            return resolve(Tracer::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private function log(\Throwable $e): void
    {
        try {
            logger()->warning('链路上下文初始化失败，已降级', ['exception' => $e]);
        } catch (\Throwable) {
            // logger 不可用时忽略，避免二次异常。
        }
    }
}
