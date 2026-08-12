<?php

declare(strict_types=1);

namespace Kode\Framework\Observability\Middleware;

use Kode\Framework\Observability\Trace\TraceContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 链路追踪中间件（全局最外层）
 *
 *  - 请求进入：调用 {@see TraceContext::ensure()} 确定/复用 trace_id 与 span_id，
 *    并回写 $_SERVER 桥接 kode/exception 的异常 tracer。
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
        TraceContext::ensure($request);

        $response = $handler->handle($request);

        foreach (TraceContext::responseHeaders() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
