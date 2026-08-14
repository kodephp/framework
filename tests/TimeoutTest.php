<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Resilience\Events\TimeoutExceeded as TimeoutExceededEvent;
use Kode\Framework\Resilience\Timeout;
use Kode\Framework\Resilience\TimeoutExceeded;
use Kode\Framework\Resilience\TimeoutScheduler;
use Kode\Framework\Resilience\TimeoutScheduler\SyncTimeoutScheduler;
use PHPUnit\Framework\TestCase;

/**
 * Timeout 原语单元测试（不依赖 fiber 运行时：用注入的假调度器验证逻辑编排）。
 */
final class TimeoutTest extends TestCase
{
    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->events = [];
    }

    private function dispatcher(): \Closure
    {
        return function (object $event): void {
            $this->events[] = $event;
        };
    }

    /**
     * 假调度器：总是返回固定值，用于验证「未超时」路径不派发事件。
     */
    private function okScheduler(mixed $value): TimeoutScheduler
    {
        return new class($value) implements TimeoutScheduler {
            public function __construct(private mixed $value) {}

            public function run(callable $op, float $seconds): mixed
            {
                return $this->value;
            }
        };
    }

    /**
     * 假调度器：总是抛 TimeoutExceeded，用于验证降级 / 派发 / 透传。
     */
    private function failScheduler(float $seconds): TimeoutScheduler
    {
        return new class($seconds) implements TimeoutScheduler {
            public function __construct(private float $seconds) {}

            public function run(callable $op, float $seconds): mixed
            {
                throw new TimeoutExceeded($this->seconds, 'anonymous');
            }
        };
    }

    public function testReturnsValueWhenNotTimedOut(): void
    {
        $timeout = new Timeout($this->okScheduler('ok'), 1.0, true, $this->dispatcher());

        self::assertSame('ok', $timeout->run(fn () => null));
        self::assertCount(0, $this->events, '未超时不应派发事件');
    }

    public function testThrowsTimeoutExceededAndDispatchesEvent(): void
    {
        $timeout = new Timeout($this->failScheduler(2.0), 2.0, true, $this->dispatcher());

        try {
            $timeout->run(fn () => null, ['label' => 'slow-db']);
            $this->fail('应抛 TimeoutExceeded');
        } catch (TimeoutExceeded $e) {
            self::assertSame(2.0, $e->seconds);
            self::assertCount(1, $this->events);
            self::assertInstanceOf(TimeoutExceededEvent::class, $this->events[0]);
            self::assertSame('slow-db', $this->events[0]->label);
            self::assertSame(2.0, $this->events[0]->seconds);
        }
    }

    public function testFallbackReturnedOnTimeout(): void
    {
        $timeout = new Timeout($this->failScheduler(1.0), 1.0, true, $this->dispatcher());

        $result = $timeout->run(fn () => null, [
            'label' => 'fetch',
            'fallback' => static fn (TimeoutExceeded $e) => 'cached:' . $e->seconds,
        ]);

        self::assertSame('cached:1', $result, '超时应返回 fallback 值');
        self::assertCount(1, $this->events, 'fallback 路径仍应派发事件');
    }

    public function testNoThrowReturnsNullWhenConfigured(): void
    {
        $timeout = new Timeout($this->failScheduler(0.5), 0.5, false, $this->dispatcher());

        self::assertNull($timeout->run(fn () => null, ['label' => 'optional']));
        self::assertCount(1, $this->events);
    }

    public function testSyncSchedulerWithinBudget(): void
    {
        $scheduler = new SyncTimeoutScheduler();
        $result = $scheduler->run(static fn () => usleep(20_000) ?? 'done', 0.2);

        self::assertSame('done', $result);
    }

    public function testSyncSchedulerDetectsOverBudget(): void
    {
        $scheduler = new SyncTimeoutScheduler();

        $this->expectException(TimeoutExceeded::class);
        $scheduler->run(static function (): void {
            usleep(120_000);
        }, 0.05);
    }

    public function testExplicitSyncSchedulerThroughTimeoutDetectsOverBudget(): void
    {
        $timeout = new Timeout(null, 0.05, true, null);

        $this->expectException(TimeoutExceeded::class);
        $timeout->run(static function (): void {
            usleep(120_000);
        }, ['scheduler' => 'sync']);
    }

    public function testExplicitFiberSchedulerPreemptsSuspendingOp(): void
    {
        if (!class_exists(\Kode\Fibers\Fibers::class)) {
            self::markTestSkipped('kode/fibers 不可用，跳过 fiber 抢占验证');
        }

        $timeout = new Timeout(null, 0.05, true, null);

        $this->expectException(TimeoutExceeded::class);
        // 协程内挂起 0.5s，远超时 0.05s —— fiber 调度器应真实抢占。
        $timeout->run(static function (): string {
            \Kode\Fibers\Fibers::sleep(0.5);

            return 'slow';
        }, ['scheduler' => 'fiber']);
    }
}
