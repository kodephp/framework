<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Lifecycle\WorkerStarting;
use Kode\Framework\Observability\Trace\Contracts\SpanExporter;
use Kode\Framework\Observability\Trace\Span;
use Kode\Framework\Observability\Trace\Tracer;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * 常驻 worker 的周期性 span 导出（flush_interval_ms）集成测试。
 *
 * 背景：async=true 时请求结束只做内存入队，真实发送要等 drain()。此前框架只在
 * shutdown / 停机钩子里 drain——常驻 worker 长跑期间两者都不触发，outbox 只会
 * 堆到 max_outbox 然后开始丢最旧的 span，配置里的 flush_interval_ms 是个没人读的哑键。
 * 现在 HttpServer 在 worker 事件循环内派发 WorkerStarting 时带上定时器注册器，
 * provider 据此注册周期 drain。
 */
final class TracingPeriodicDrainTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('OBS_TRACING_ENABLED=true');
        putenv('OBS_TRACING_SAMPLE_RATIO=1.0');
        parent::setUp();
        $this->bootApp();
        Tracer::resetOutbox();
    }

    #[RunInSeparateProcess]
    public function test_worker_starting_registers_periodic_drain_timer(): void
    {
        // 夹具配置默认 flush_interval_ms=2000
        $sink = [];
        $this->installMemoryExporter($sink);

        $registered = [];
        event(new WorkerStarting(3, static function (float $interval, callable $cb) use (&$registered): int {
            $registered[] = [$interval, $cb];

            return count($registered);
        }));

        self::assertCount(1, $registered, 'worker 启动应注册一个周期导出定时器');
        self::assertSame(2.0, $registered[0][0], 'flush_interval_ms=2000 → 2.0 秒（addTimer 收秒）');

        // 定时器回调触发 = 离请求路径把 outbox 发出去
        // （end() 在 flush_on_request_end + async 下只入队 outbox，不发送）
        $this->recordSpan('tick-op');
        ($registered[0][1])();

        self::assertCount(1, $sink, '定时器触发时应导出待发送 span');
        self::assertInstanceOf(Span::class, $sink[0]);
    }

    #[RunInSeparateProcess]
    public function test_no_timer_without_a_loop_registrar(): void
    {
        // FPM / CLI：事件里没有定时器注册器，静默跳过（仍靠 shutdown 钩子导出），不得抛错。
        event(new WorkerStarting(0));
        self::assertSame(0, tracer()->exportFailures());
    }

    #[RunInSeparateProcess]
    public function test_timer_exception_never_escapes_the_loop(): void
    {
        $sink = [];
        $this->installMemoryExporter($sink);

        $cb = null;
        event(new WorkerStarting(1, static function (float $interval, callable $c) use (&$cb): int {
            $cb = $c;

            return 1;
        }));
        self::assertNotNull($cb);

        // 导出器抛异常（Collector 不可达）时回调必须吞掉，绝不能打死 worker 事件循环
        app()->container->singleton(SpanExporter::class, static function (): SpanExporter {
            return new class() implements SpanExporter {
                public function export(array $spans): void
                {
                    throw new \RuntimeException('collector unreachable');
                }

                public function name(): string
                {
                    return 'failing';
                }
            };
        });
        Tracer::resetOutbox();
        $this->recordSpan('boom');

        $cb();
        self::assertSame(1, tracer()->exportFailures(), '失败应记入退避计数而不是抛出');
    }

    /** 录一个 span 并结束它：async 下等于「入队 outbox、尚未发送」。 */
    private function recordSpan(string $name): void
    {
        $t = tracer();
        $t->end($t->start($name, [], 1, true));
        self::assertSame(1, $t->pendingCount(), '前置条件：span 应已入队待导出');
    }

    /**
     * @param array<int, Span> $sink
     */
    private function installMemoryExporter(array &$sink): void
    {
        app()->container->singleton(SpanExporter::class, static function () use (&$sink): SpanExporter {
            return new class($sink) implements SpanExporter {
                public function __construct(private array &$sink)
                {
                }

                public function export(array $spans): void
                {
                    foreach ($spans as $span) {
                        $this->sink[] = $span;
                    }
                }

                public function name(): string
                {
                    return 'memory';
                }
            };
        });
    }
}
