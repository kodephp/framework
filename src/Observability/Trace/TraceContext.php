<?php

declare(strict_types=1);

namespace Kode\Framework\Observability\Trace;

use Kode\Context\Context;
use Kode\Http\Psr7\Message\LazyHeaderAware;
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
     * 定向读取入向链路头，零解析优先。
     *
     * 对实现 {@see LazyHeaderAware} 的懒请求（框架热路径），走 {@see peekHeader()}——
     * 原生报文一次 stripos 定向扫描（~75ns/头 @500B 报文），不触发 header 全量规范化
     * 与 server params 引导构建；非懒请求退化标准 getHeaderLine。
     */
    private static function inboundHeader(ServerRequestInterface $request, string $name): string
    {
        if ($request instanceof LazyHeaderAware) {
            return $request->peekHeader($name) ?? '';
        }

        return $request->getHeaderLine($name);
    }

    /**
     * 为当前请求确保链路上下文存在。幂等：Trace ID 与 Span ID 均齐备则不变。
     *
     * 读取优先级：traceparent → (X-Trace-Id + X-Span-Id) → 全新生成。
     * 同时把 trace_id 回写 $_SERVER，桥接 kode/exception 的异常 tracer。
     *
     * 热路径（无入向链路）成本：2× peekHeader（~150ns）+ 1× random_bytes(24)（~310ns）+
     * 2× bin2hex + 3× Context::set + 1× $_SERVER 回写；零 header 全量解析。
     * 注：曾尝试 SHA-256 计数器 DRBG 派生替代 random_bytes——实测本（native）环境下
     * hash('sha256') ≈ 625ns > random_bytes+bin2hex ≈ 310ns，为负优化，已回退。
     */
    public static function ensure(ServerRequestInterface $request): void
    {
        // 单次 has 复用：完整链路（TRACE + SPAN 齐备）走幂等早退；「仅 TRACE_ID」
        // （kode/http syncTraceContext / 测试预置）由下方回退补充 span，避免二次查找。
        $hasTrace = Context::has(Context::TRACE_ID);
        if ($hasTrace && Context::has(Context::SPAN_ID)) {
            // 链路完整（例如上游中间件/测试预置），仍同步 $_SERVER 以便异常 tracer 一致。
            self::syncServer(Context::getString(Context::TRACE_ID));
            return;
        }

        $traceId = null;
        $spanId = null;
        $parentSpanId = null;

        $traceparent = self::inboundHeader($request, self::HEADER_TRACEPARENT);
        $traceFlags = null;
        if ($traceparent !== '' && preg_match(self::TRACEPARENT_RE, $traceparent, $m) === 1) {
            $traceId = $m[2];
            $spanId = $m[3];
            $traceFlags = (hexdec($m[4]) & 1) === 1 ? 1 : 0;
            $parentSpanId = null; // 入向 span 成为本服务的 parent
            Context::set(Context::PARENT_SPAN_ID, $m[3]);
        } else {
            $incomingTrace = self::inboundHeader($request, self::HEADER_TRACE_ID);
            if ($incomingTrace !== '') {
                $traceId = $incomingTrace;
                $incomingSpan = self::inboundHeader($request, self::HEADER_SPAN_ID);
                if ($incomingSpan !== '') {
                    $parentSpanId = $incomingSpan;
                    Context::set(Context::PARENT_SPAN_ID, $parentSpanId);
                }
            }
        }

        if ($traceId === null) {
            // 回退 Context 已预置的 TRACE_ID（已由 hasTrace 判定存在），
            // 保留既有链路值、只补齐缺环的 span_id。
            $traceId = $hasTrace ? Context::getString(Context::TRACE_ID) : null;
        }
        if ($traceId === null) {
            // 全新链路：单次 CSPRNG 取 24 字节，切片出 trace_id(16) + span_id(8)。
            $rand = random_bytes(24);
            $traceId = bin2hex(substr($rand, 0, 16));
            $spanId = bin2hex(substr($rand, 16, 8));
        } elseif ($spanId === null) {
            // 仅入向带 X-Trace-Id 或 Context 已有 TRACE_ID——span_id 缺环时补齐，
            // 避免响应 traceparent 复用残留/空值。
            $spanId = bin2hex(random_bytes(8));
        }

        // 单次 merge 批量写：3×Context::set 每次都要重解析执行单元（store()），
        // merge 只解析一次 + 循环写入，热路径省 2× scope/WeakMap 查找（~0.6µs）。
        // 入向含 traceparent 时尊重上游采样位（$traceFlags），否则默认采样（1）。
        Context::merge([
            Context::TRACE_ID    => $traceId,
            Context::SPAN_ID     => $spanId,
            Context::TRACE_FLAGS => $traceFlags ?? 1,
        ]);

        self::syncServer($traceId);
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
     *
     * @param string $traceId 已确定的 trace_id（直传，避免二次 Context 读取）
     */
    private static function syncServer(string $traceId): void
    {
        if ($traceId !== '') {
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

    /**
     * 公开生成器（供 Tracer 在无入向链路时兜底生成）。
     */
    public static function newTraceId(): string
    {
        return self::generateTraceId();
    }

    /**
     * 公开生成器（供 Tracer 在无入向链路时兜底生成）。
     */
    public static function newSpanId(): string
    {
        return self::generateSpanId();
    }
}
