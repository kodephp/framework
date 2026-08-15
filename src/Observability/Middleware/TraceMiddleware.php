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
    /**
     * 链路追踪管理器（启动期注入一次，避免每请求 resolve() + 全局 app() 访问）。
     *
     * 为 null 或 {@see Tracer::isEnabled()} 为 false 时跳过 span 录制，仅做最廉价的链路头附加。
     */
    public function __construct(
        private readonly ?Tracer $tracer = null,
    ) {
    }

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
        $tracer = $this->tracer;
        // 仅当 tracing 启用且「本请求被采样」才开根 span——未采样直接跳过 span 创建，
        // 避免 100% 请求都付出上下文 / 对象开销（sample_ratio < 1 时绝大部分请求走此快路径）。
        // 链路头（traceparent / X-Trace-Id）仍由下方 TraceContext::responseHeaders() 统一附加。
        if ($tracer !== null && $tracer->isEnabled() && $tracer->decideSampled()) {
            $root = $tracer->start(
                $request->getMethod() . ' ' . $request->getUri()->getPath(),
                [
                    'http.method' => $request->getMethod(),
                    'http.route' => $request->getUri()->getPath(),
                    'http.scheme' => $request->getUri()->getScheme(),
                    'http.host' => $request->getUri()->getAuthority(),
                ],
                SpanKind::SERVER,
                true,
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

    private function log(\Throwable $e): void
    {
        try {
            logger()->warning('链路上下文初始化失败，已降级', ['exception' => $e]);
        } catch (\Throwable) {
            // logger 不可用时忽略，避免二次异常。
        }
    }
}
