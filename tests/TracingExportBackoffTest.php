<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Context\Context;
use Kode\Framework\Observability\Trace\Contracts\SpanExporter;
use Kode\Framework\Observability\Trace\Tracer;
use PHPUnit\Framework\TestCase;

/**
 * span 导出失败退避（circuit breaker）单元测试。
 *
 * 背景：Collector 不可达时，drain() 每次都要付满一次 curl 超时（默认 2s）并打一条
 * 带完整异常的 warning。tick 定时器 / FPM shutdown 都会周期性撞上它 —— 11 个 worker
 * 长跑几小时就是上万条重复告警，且每个窗口都在阻塞时间里空转。
 * 故失败后指数退避：窗口内直接返回 0（不碰网络、不打日志），日志只在第 1、11、21… 次
 * 失败时打一条，恢复时补一条 info，span 全程留在 outbox 等重试。
 *
 * 断言口径：
 *  - 「不碰网络」= 导出器调用次数不再增长（阻塞来源就是它）；
 *  - 「不打日志」= 内部 suppressed 计数增长（日志条数 = 失败数 - 被压掉的数）；
 *  - 不依赖 logger()（单测无容器，日志调用本就 try/catch 吞掉）。
 *
 * 导出器一律内存假件，绝不连真实 Collector。
 */
final class TracingExportBackoffTest extends TestCase
{
    protected function setUp(): void
    {
        Context::clear();
        Tracer::resetOutbox();
    }

    protected function tearDown(): void
    {
        Context::clear();
        Tracer::resetOutbox();
    }

    public function test_failure_opens_backoff_window_and_stops_retrying(): void
    {
        $calls = 0;
        $t = $this->tracer($this->failingResolver($calls), ['export_backoff_ms' => 10_000]);

        $this->recordSpan($t, 'op');
        self::assertSame(0, $t->drain(), '失败的导出不算成功数');
        self::assertSame(1, $t->exportFailures());
        self::assertGreaterThan(0, $t->exportRetryInMs(), '失败后应留下退避窗口');

        // 窗口内再 drain：绝不能再去碰网络（否则每轮都阻塞满超时）
        $this->recordSpan($t, 'op2');
        self::assertSame(0, $t->drain());
        self::assertSame(1, $calls, '退避窗口内不应再次调用导出器');
    }

    public function test_failed_batch_is_requeued_instead_of_dropped(): void
    {
        $calls = 0;
        $t = $this->tracer($this->failingResolver($calls), ['export_backoff_ms' => 10_000]);

        $this->recordSpan($t, 'op');
        $t->drain();
        self::assertSame(1, $t->pendingCount(), '失败批次必须回灌待导出队列，而不是丢弃');

        $t->drain(true);
        self::assertSame(2, $calls);
    }

    public function test_force_drain_bypasses_the_window(): void
    {
        $calls = 0;
        $t = $this->tracer($this->failingResolver($calls), ['export_backoff_ms' => 10_000]);

        $this->recordSpan($t, 'op');
        $t->drain();
        self::assertSame(1, $calls);

        $t->drain(true);
        self::assertSame(2, $calls, 'force（停机前最后一次机会）应无视窗口');
    }

    public function test_window_expires_and_retries(): void
    {
        $calls = 0;
        $t = $this->tracer($this->failingResolver($calls), ['export_backoff_ms' => 20]);

        $this->recordSpan($t, 'op');
        $t->drain();
        $t->drain();
        self::assertSame(1, $calls);

        usleep(30_000);
        $t->drain();
        self::assertSame(2, $calls, '窗口过后应恢复尝试');
    }

    public function test_window_grows_exponentially_and_caps(): void
    {
        $calls = 0;
        $t = $this->tracer(
            $this->failingResolver($calls),
            ['export_backoff_ms' => 100, 'export_backoff_max_ms' => 400],
        );

        $this->recordSpan($t, 'op');
        // 指数退避：100 → 200 → 400 → 封顶 400（force 只跳过窗口，失败计数照常累加）
        foreach ([100, 200, 400, 400] as $i => $ms) {
            $t->drain(true);
            self::assertSame($i + 1, $calls);
            self::assertSame($i + 1, $t->exportFailures());
            self::assertLessThanOrEqual($ms, $t->exportRetryInMs(), '第 ' . ($i + 1) . " 次失败窗口应 ≤ {$ms}ms");
            self::assertGreaterThan(0, $t->exportRetryInMs());
        }
    }

    public function test_success_after_failures_resets_state_and_exports_accumulated(): void
    {
        $calls = 0;
        $exported = 0;
        $t = $this->tracer($this->flakyResolver($calls, $exported, 1), ['export_backoff_ms' => 10]);

        $this->recordSpan($t, 'op');
        self::assertSame(0, $t->drain(), '首次失败');
        self::assertSame(0, $t->drain(), '窗口内被跳过');
        self::assertSame(1, $calls, 'span 应攒在 outbox 里等重试，只付了一次网络');

        usleep(20_000);
        self::assertSame(1, $t->drain(), '恢复后应把攒下的 span 一次发出');
        self::assertSame(0, $t->exportFailures(), '成功后退避状态清零');
        self::assertSame(0, $t->exportRetryInMs());
        self::assertSame(1, $exported);
    }

    public function test_logs_are_deduplicated_every_tenth_failure(): void
    {
        $calls = 0;
        $t = $this->tracer($this->failingResolver($calls), ['export_backoff_ms' => 1]);

        $this->recordSpan($t, 'op');
        // 窗口只有 1ms，故每次 drain 都会真尝试；第 1、11 次打日志，其余压掉。
        for ($i = 0; $i < 12; $i++) {
            $t->drain(true);
            usleep(2_000);
        }

        self::assertSame(12, $calls);
        self::assertSame(12, $t->exportFailures(), '12 次真尝试');
        self::assertSame(2, $t->exportWarnings(), '12 次失败只该写出 2 条告警（第 1、11 次）');
    }

    public function test_zero_backoff_disables_throttling(): void
    {
        $calls = 0;
        $t = $this->tracer($this->failingResolver($calls), ['export_backoff_ms' => 0]);

        $this->recordSpan($t, 'op');
        $t->drain();
        $t->drain();
        self::assertSame(2, $calls, 'export_backoff_ms=0 时保持旧行为（每次真试）');
        self::assertSame(0, $t->exportRetryInMs());
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $config
     */
    private function tracer(\Closure $resolver, array $config): Tracer
    {
        // async=false + flush_on_request_end=false：span 留在执行单元缓冲里，
        // 由测试自己决定何时 drain（避免异步 outbox 与退避状态混在一起难以断言）。
        return new Tracer(true, $config + ['flush_on_request_end' => false], null, $resolver, false);
    }

    /** 始终失败的导出器：每次 export 记一次调用数并抛异常。 */
    private function failingResolver(int &$calls): \Closure
    {
        return function () use (&$calls): SpanExporter {
            return new class($calls) implements SpanExporter {
                public function __construct(private int &$calls)
                {
                }

                public function export(array $spans): void
                {
                    ++$this->calls;
                    throw new \RuntimeException('collector unreachable');
                }

                public function name(): string
                {
                    return 'failing';
                }
            };
        };
    }

    /** 前 $failTimes 次失败、之后成功的导出器（模拟 Collector 恢复）。 */
    private function flakyResolver(int &$calls, int &$exported, int $failTimes): \Closure
    {
        return function () use (&$calls, &$exported, $failTimes): SpanExporter {
            return new class($calls, $exported, $failTimes) implements SpanExporter {
                private int $remaining;

                public function __construct(private int &$calls, private int &$exported, int $failTimes)
                {
                    $this->remaining = $failTimes;
                }

                public function export(array $spans): void
                {
                    ++$this->calls;
                    if ($this->remaining > 0) {
                        --$this->remaining;
                        throw new \RuntimeException('collector unreachable');
                    }
                    $this->exported += count($spans);
                }

                public function name(): string
                {
                    return 'flaky';
                }
            };
        };
    }

    /** 录一个 span 进当前执行单元缓冲（start+end，不触发自动 flush）。 */
    private function recordSpan(Tracer $t, string $name): void
    {
        $span = $t->start($name, [], 1, true);
        $t->end($span);
    }

    private static function outboxSize(): int
    {
        $prop = new \ReflectionProperty(Tracer::class, 'outbox');

        return count((array) $prop->getValue());
    }
}
