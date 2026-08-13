<?php

declare(strict_types=1);

namespace Kode\Framework\Observability\Trace;

use Kode\Context\Context;
use Kode\Framework\Observability\Trace\Contracts\SpanExporter;
use Psr\Log\LoggerInterface;

/**
 * 分布式追踪管理器（薄壳层核心）。
 *
 * 职责：
 *  - 录制 span：{@see start()} 基于当前链路（kode/context 的 trace_id/span_id）开子跨度，
 *    结束 {@see end()} 回填时长与状态，进入待导出缓冲。
 *  - 采样：根 span 按 sample_ratio 决策，子 span 继承父采样，保证一条链路一致。
 *  - 隔离：active 栈与待导出缓冲存于 kode/context（按执行单元隔离），并发 fiber / 进程 /
 *    线程各持独立链路，天然支持 kode/fibers 的 active runtime。
 *  - 导出：根 span 结束且配置 flush_on_request_end 时自动 flush；或手动 {@see flush()}。
 *    导出器（SpanExporter）由容器注入，失败仅告警、不阻断请求。
 *
 * 设计立场：框架只做「录制 + 标准 OTLP 导出钩子」，不内置 OTel SDK；真实后端
 * （gRPC、第三方 APM）实现 SpanExporter 注入即零改动接入——与配置中心、服务发现同哲学。
 */
final class Tracer
{
    private const string CTX_STACK = 'kode.tracing.stack';

    private const string CTX_BUFFER = 'kode.tracing.buffer';

    private ?SpanExporter $exporter = null;

    /**
     * @param array<string, mixed> $config     observability.tracing 配置
     * @param \Closure|null        $dispatcher 事件派发器（注入，解耦事件系统启动顺序）
     * @param \Closure|null        $exporterResolver 解析 SpanExporter（延迟，避免未启用时构建）
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly array $config = [],
        private readonly ?\Closure $dispatcher = null,
        private readonly ?\Closure $exporterResolver = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * 开启一个 span（自动作为当前 active span 的子跨度）。
     *
     * @param array<string, mixed> $attributes
     */
    public function start(string $name, array $attributes = [], int $kind = SpanKind::INTERNAL): Span
    {
        if (!$this->enabled) {
            return new Span('', '', null, $name, $kind, microtime(true), $attributes, false, true);
        }

        $active = $this->active();
        $traceId = $this->ensureTraceId();

        if ($active !== null) {
            // 子跨度：推进当前 span，parent 指向 active
            TraceContext::childSpan();
            $spanId = TraceContext::spanId() ?? TraceContext::newSpanId();
            $parentSpanId = $active->spanId;
            $sampled = $active->sampled;
        } else {
            // 根跨度：复用请求级 span_id（与响应 traceparent 一致），采样按比率决策
            $spanId = TraceContext::spanId() ?? TraceContext::newSpanId();
            $parentSpanId = TraceContext::parentSpanId();
            $sampled = $this->decideSampled();
        }

        $span = new Span(
            traceId: $traceId,
            spanId: $spanId,
            parentSpanId: $parentSpanId,
            name: $name,
            kind: $kind,
            start: microtime(true),
            attributes: $attributes,
            sampled: $sampled,
        );

        $this->pushStack($span);

        return $span;
    }

    /**
     * 便捷包裹：开 span → 执行 → 结束（异常时记错误并上抛）。
     *
     * @template T
     * @param callable(Span): T $work
     * @return T
     */
    public function span(string $name, array $attributes, int $kind, callable $work): mixed
    {
        $span = $this->start($name, $attributes, $kind);
        try {
            return $work($span);
        } catch (\Throwable $e) {
            $span->recordException($e);
            $this->end($span, SpanStatus::ERROR, $e->getMessage());
            throw $e;
        }
    }

    /**
     * 结束 span（回填时长/状态）、入缓冲，根 span 结束时按需 flush。
     */
    public function end(Span $span, int $statusCode = SpanStatus::OK, string $statusMessage = ''): void
    {
        if ($span->noop || !$span->sampled) {
            $this->popStack($span);

            return;
        }

        if ($span->end === null) {
            $span->finish(microtime(true), $statusCode, $statusMessage);
        }

        $this->popStack($span);
        $this->appendBuffer($span);

        if ($this->stack() === [] && !empty($this->config['flush_on_request_end'])) {
            $this->flush();
        }
    }

    /**
     * 记录异常到指定 span（不抛，可继续处理）。
     */
    public function recordException(Span $span, \Throwable $e, array $attributes = []): void
    {
        $span->recordException($e, $attributes);
    }

    /**
     * 当前 active（栈顶）span。
     */
    public function active(): ?Span
    {
        $stack = $this->stack();

        return $stack === [] ? null : end($stack);
    }

    /**
     * 导出当前执行单元缓冲的 span。
     *
     * @return int 成功导出的 span 数（0 = 无数据或失败）
     */
    public function flush(): int
    {
        if (!$this->enabled) {
            return 0;
        }

        $buffer = $this->buffer();
        if ($buffer === []) {
            return 0;
        }

        $count = count($buffer);
        $exporter = $this->exporter();
        if ($exporter === null) {
            return 0;
        }

        $name = $exporter->name();
        try {
            $exporter->export($buffer);
            Context::set(self::CTX_BUFFER, []);
            $this->dispatch(new SpansFlushed($count, $name, true));

            return $count;
        } catch (\Throwable $e) {
            // 导出失败：保留缓冲以待下次 flush 重试；仅告警，不阻断业务。
            try {
                logger()->warning('span 导出失败，已保留缓冲待重试', [
                    'exporter' => $name,
                    'count' => $count,
                    'exception' => $e,
                ]);
            } catch (\Throwable) {
                // logger 不可用时忽略
            }
            $this->dispatch(new SpansFlushed($count, $name, false, $e->getMessage()));

            return 0;
        }
    }

    /**
     * 当前缓冲中的 span 数。
     */
    public function buffered(): int
    {
        return count($this->buffer());
    }

    /**
     * 当前导出器名称（未解析/未启用时返回 null）。
     */
    public function exporterName(): ?string
    {
        return $this->exporter()?->name();
    }

    // ------------------------------------------------------------------
    // 内部：执行单元隔离的栈 / 缓冲（kode/context）
    // ------------------------------------------------------------------

    /** @return array<int, Span> */
    private function stack(): array
    {
        return Context::get(self::CTX_STACK, []);
    }

    private function pushStack(Span $span): void
    {
        $stack = $this->stack();
        $stack[] = $span;
        Context::set(self::CTX_STACK, $stack);
    }

    private function popStack(Span $span): void
    {
        $stack = $this->stack();
        $stack = array_values(array_filter($stack, static fn (Span $s): bool => $s !== $span));
        Context::set(self::CTX_STACK, $stack);
    }

    /** @return array<int, Span> */
    private function buffer(): array
    {
        return Context::get(self::CTX_BUFFER, []);
    }

    private function appendBuffer(Span $span): void
    {
        $buf = $this->buffer();
        $buf[] = $span;
        $max = (int) ($this->config['max_batch'] ?? 512);
        if (count($buf) > $max) {
            array_shift($buf);
        }
        Context::set(self::CTX_BUFFER, $buf);
    }

    private function ensureTraceId(): string
    {
        $traceId = TraceContext::traceId();
        if ($traceId !== null) {
            return $traceId;
        }

        $traceId = TraceContext::newTraceId();
        Context::set(Context::TRACE_ID, $traceId);

        return $traceId;
    }

    private function decideSampled(): bool
    {
        $ratio = (float) ($this->config['sample_ratio'] ?? 1.0);
        if ($ratio >= 1.0) {
            return true;
        }
        if ($ratio <= 0.0) {
            return false;
        }

        return mt_rand() / mt_getrandmax() <= $ratio;
    }

    private function exporter(): ?SpanExporter
    {
        if ($this->exporter !== null) {
            return $this->exporter;
        }

        if ($this->exporterResolver !== null) {
            $resolved = ($this->exporterResolver)();
            if ($resolved instanceof SpanExporter) {
                $this->exporter = $resolved;

                return $this->exporter;
            }
        }

        return null;
    }

    private function dispatch(object $event): void
    {
        if ($this->dispatcher !== null) {
            ($this->dispatcher)($event);
        }
    }
}
