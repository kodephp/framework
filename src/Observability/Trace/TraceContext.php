<?php

declare(strict_types=1);

namespace Kode\Framework\Observability\Trace;

use Kode\Context\Context;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 分布式链路上下文（薄封装 kode/context 的 TRACE_ID / SPAN_ID 等原生常量）
 *
 * 职责：
 *  1. 在请求进入时为当前执行单元（fiber / 进程 / 线程）确定 trace_id 与 span_id：
 *     优先复用入向 W3C `traceparent` 或 `X-Trace-Id`/`X-Span-Id`；否则生成新链路。
 *  2. 将链路写入 kode/context（按执行单元隔离，天然支持并发），并回写
 *     `$_SERVER['HTTP_X_TRACE_ID']` 使 kode/exception 的 DistributedTracer 复用同一
 *     trace_id——保证「正常响应头」与「异常响应体」的 trace_id 完全一致。
 *  3. 提供 {@see responseHeaders()}（下发 traceparent + X-Trace-Id/X-Span-Id）与
 *     {@see outgoingHeaders()}（注入到下游 HTTP 调用，实现跨服务串联）。
 *
 * 设计立场：链路标识全部存于 kode/context，框架不另起一套；W3C traceparent 仅做
 * 兼容透传，不强制采样策略（采样由 kode/context 的 sampled 标志管理）。
 */
final class TraceContext
{
    public const string HEADER_TRACEPARENT = 'traceparent';
    public const string HEADER_TRACE_ID = 'X-Trace-Id';
    public const string HEADER_SPAN_ID = 'X-Span-Id';

    private const string TRACEPARENT_RE = '/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/';

    /**
     * 为当前请求确保链路上下文存在。幂等：已存在则不变。
     *
     * 读取优先级：traceparent → (X-Trace-Id + X-Span-Id) → 全新生成。
     * 同时把 trace_id 回写 $_SERVER，桥接 kode/exception 的异常 tracer。
     */
    public static function ensure(ServerRequestInterface $request): void
    {
        if (Context::has(Context::TRACE_ID)) {
            // 已存在（例如上游中间件/测试预置），仍同步 $_SERVER 以便异常 tracer 一致。
            self::syncServer();
            return;
        }

        $traceId = null;
        $spanId = null;
        $parentSpanId = null;

        $traceparent = $request->getHeaderLine(self::HEADER_TRACEPARENT);
        if ($traceparent !== '' && preg_match(self::TRACEPARENT_RE, $traceparent, $m) === 1) {
            $traceId = $m[2];
            $spanId = $m[3];
            $parentSpanId = null; // 入向 span 成为本服务的 parent
            Context::set(Context::PARENT_SPAN_ID, $m[3]);
        } else {
            $incomingTrace = $request->getHeaderLine(self::HEADER_TRACE_ID);
            $incomingSpan = $request->getHeaderLine(self::HEADER_SPAN_ID);
            if ($incomingTrace !== '') {
                $traceId = $incomingTrace;
                $parentSpanId = $incomingSpan !== '' ? $incomingSpan : null;
                if ($parentSpanId !== null) {
                    Context::set(Context::PARENT_SPAN_ID, $parentSpanId);
                }
            }
        }

        if ($traceId === null) {
            $traceId = self::generateTraceId();
        }
        if ($spanId === null) {
            $spanId = self::generateSpanId();
        }

        Context::set(Context::TRACE_ID, $traceId);
        Context::set(Context::SPAN_ID, $spanId);
        Context::set(Context::TRACE_FLAGS, Context::get(Context::TRACE_FLAGS) ?? 1);

        self::syncServer();
    }

    public static function traceId(): ?string
    {
        return Context::getString(Context::TRACE_ID);
    }

    public static function spanId(): ?string
    {
        return Context::getString(Context::SPAN_ID);
    }

    public static function parentSpanId(): ?string
    {
        return Context::getString(Context::PARENT_SPAN_ID);
    }

    /**
     * 开启一个子跨度：基于当前 trace 生成新的 span_id，并把当前 span 记为 parent。
     *
     * 返回新 span_id；同时更新 Context 的 SPAN_ID（当前 span 推进为子 span），
     * parent 指向上一层 span。调用方负责在子调用结束后恢复（如需）。
     */
    public static function childSpan(): string
    {
        $current = self::spanId();
        $newSpan = self::generateSpanId();
        if ($current !== null) {
            Context::set(Context::PARENT_SPAN_ID, $current);
        }
        Context::set(Context::SPAN_ID, $newSpan);

        return $newSpan;
    }

    /**
     * 响应头：W3C traceparent + 兼容用 X-Trace-Id / X-Span-Id。
     *
     * @return array<string, string>
     */
    public static function responseHeaders(): array
    {
        $traceId = self::traceId();
        $spanId = self::spanId();
        if ($traceId === null || $spanId === null) {
            return [];
        }

        $flags = (Context::get(Context::TRACE_FLAGS) === 1) ? '01' : '00';

        return [
            self::HEADER_TRACEPARENT => "00-{$traceId}-{$spanId}-{$flags}",
            self::HEADER_TRACE_ID => $traceId,
            self::HEADER_SPAN_ID => $spanId,
        ];
    }

    /**
     * 出向链路头：注入到下游 HTTP 客户端请求（跨服务串联）。
     *
     * @return array<string, string>
     */
    public static function outgoingHeaders(): array
    {
        return self::responseHeaders();
    }

    /**
     * 把当前 trace_id 同步到 $_SERVER，使 kode/exception 的 DistributedTracer
     * 在异常时复用同一 trace_id（它从 HTTP_X_TRACE_ID 读取父链路）。
     */
    private static function syncServer(): void
    {
        $traceId = self::traceId();
        if ($traceId !== null) {
            $_SERVER['HTTP_X_TRACE_ID'] = $traceId;
        }
    }

    private static function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
