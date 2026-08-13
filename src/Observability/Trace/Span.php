<?php

declare(strict_types=1);

namespace Kode\Framework\Observability\Trace;

/**
 * Span 运行期表示（一次操作的链路单元）。
 *
 * 身份字段（traceId/spanId/parentSpanId/name/kind/start/attributes）只读；
 * end/statusCode/statusMessage/events 由 Tracer 在结束时回填。
 * 通过 {@see OtlpMapper} 序列化为 OTLP span 结构，供导出器发送。
 *
 * 注意：Span 实例由 Tracer 存于 kode/context（按执行单元隔离），
 * 支持并发 fiber / 进程 / 线程各持独立链路栈。
 */
final class Span
{
    /** @var array<int, array{name: string, timestamp: float, attributes: array<string, mixed>}> */
    public array $events = [];

    public ?float $end = null;

    public int $statusCode = SpanStatus::UNSET;

    public string $statusMessage = '';

    /**
     * @param array<string, mixed> $attributes 业务/语义属性（http.method、db.statement 等）
     */
    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public readonly string $name,
        public readonly int $kind = SpanKind::INTERNAL,
        public readonly float $start = 0.0,
        public readonly array $attributes = [],
        public readonly bool $sampled = true,
        public readonly bool $noop = false,
    ) {
    }

    /**
     * 标记跨度结束（回填结束时间 + 状态）。
     */
    public function finish(float $end = null, int $statusCode = SpanStatus::OK, string $statusMessage = ''): void
    {
        $this->end = $end ?? microtime(true);
        $this->statusCode = $statusCode;
        $this->statusMessage = $statusMessage;
    }

    /**
     * 记录异常事件，并把状态置为 ERROR（不抛），便于在 end 前调用。
     */
    public function recordException(\Throwable $e, array $attributes = []): void
    {
        $this->events[] = [
            'name' => 'exception',
            'timestamp' => microtime(true),
            'attributes' => array_merge($attributes, [
                'exception.type' => $e::class,
                'exception.message' => $e->getMessage(),
                'exception.stacktrace' => $e->getTraceAsString(),
            ]),
        ];
        $this->statusCode = SpanStatus::ERROR;
        $this->statusMessage = $e->getMessage();
    }
}
