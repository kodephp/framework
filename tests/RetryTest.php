<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Resilience\Backoff\DecorrelatedJitterBackoff;
use Kode\Framework\Resilience\Backoff\ExponentialBackoff;
use Kode\Framework\Resilience\Backoff\FixedBackoff;
use Kode\Framework\Resilience\Events\RetryAttempting;
use Kode\Framework\Resilience\Events\RetryExhausted as RetryExhaustedEvent;
use Kode\Framework\Resilience\Events\RetrySucceeded;
use Kode\Framework\Resilience\Retry;
use Kode\Framework\Resilience\RetryExhausted;
use PHPUnit\Framework\TestCase;

/**
 * 重试原语单元测试（直接构造 Retry + 退避策略，注入事件/睡眠闭包捕获行为，零引导）。
 */
final class RetryTest extends TestCase
{
    /** @var array<int, object> */
    private array $events = [];

    /** @var array<int, float> */
    private array $slept = [];

    /**
     * @param \Closure|null $sleeper
     */
    private function retry(?object $backoff = null, ?\Closure $sleeper = null): Retry
    {
        $this->events = [];
        $this->slept = [];

        return new Retry(
            $backoff,
            function (object $e): object {
                $this->events[] = $e;

                return $e;
            },
            $sleeper ?? function (float $s): void {
                $this->slept[] = $s;
            },
        );
    }

    // -------------------- 退避策略数学 --------------------

    public function testFixedBackoffIsConstant(): void
    {
        $b = new FixedBackoff(0.25);
        self::assertEqualsWithDelta(0.25, $b->delay(1), 1e-9);
        self::assertEqualsWithDelta(0.25, $b->delay(5), 1e-9);
    }

    public function testExponentialBackoffWithoutJitter(): void
    {
        $b = new ExponentialBackoff(base: 0.1, factor: 2.0, cap: 10.0, jitter: false);
        self::assertEqualsWithDelta(0.1, $b->delay(1), 1e-9);
        self::assertEqualsWithDelta(0.2, $b->delay(2), 1e-9);
        self::assertEqualsWithDelta(0.4, $b->delay(3), 1e-9);
        self::assertEqualsWithDelta(0.8, $b->delay(4), 1e-9);
    }

    public function testExponentialBackoffRespectsCap(): void
    {
        $b = new ExponentialBackoff(base: 1.0, factor: 2.0, cap: 2.5, jitter: false);
        self::assertEqualsWithDelta(2.5, $b->delay(10), 1e-9, '超过 cap 应被钳制');
    }

    public function testExponentialBackoffJitterStaysWithinBounds(): void
    {
        $b = new ExponentialBackoff(base: 0.1, factor: 2.0, cap: 10.0, jitter: true, jitterRatio: 0.5);
        for ($i = 1; $i <= 20; $i++) {
            $d = $b->delay($i);
            self::assertGreaterThanOrEqual(0.0, $d);
            self::assertLessThanOrEqual(10.0, $d);
        }
    }

    public function testDecorrelatedJitterStaysWithinBounds(): void
    {
        $b = new DecorrelatedJitterBackoff(base: 0.2, cap: 5.0);
        for ($i = 1; $i <= 30; $i++) {
            $d = $b->delay($i);
            self::assertGreaterThanOrEqual(0.2 - 1e-9, $d, "第 {$i} 跳不应低于 base");
            self::assertLessThanOrEqual(5.0, $d, "第 {$i} 跳不应高于 cap");
        }
    }

    // -------------------- 重试编排 --------------------

    public function testSucceedsFirstTryDispatchesNoEvent(): void
    {
        $r = $this->retry();
        $result = $r->run(static fn () => 'ok');

        self::assertSame('ok', $result);
        self::assertCount(0, $this->events, '首次成功不派发任何事件');
        self::assertCount(0, $this->slept);
    }

    public function testSucceedsOnSecondAttemptDispatchesSucceeded(): void
    {
        $r = $this->retry(new FixedBackoff(0.1));
        $n = 0;
        $result = $r->run(static function () use (&$n): string {
            $n++;

            if ($n < 2) {
                throw new \RuntimeException('transient');
            }

            return 'recovered';
        });

        self::assertSame('recovered', $result);
        self::assertCount(2, $this->events);
        self::assertInstanceOf(RetryAttempting::class, $this->events[0]);
        self::assertSame(0.1, $this->events[0]->delay);
        self::assertInstanceOf(RetrySucceeded::class, $this->events[1]);
        self::assertSame(2, $this->events[1]->attempts);
        self::assertSame([0.1], $this->slept);
    }

    public function testExhaustsAllAttemptsAndThrows(): void
    {
        $r = $this->retry(new FixedBackoff(0.05));
        $this->expectException(RetryExhausted::class);

        try {
            $r->run(static function (): void {
                throw new \RuntimeException('always down');
            }, ['attempts' => 3]);
            self::fail('应抛出 RetryExhausted');
        } catch (RetryExhausted $e) {
            self::assertSame(3, $e->attempts);
            self::assertCount(3, $e->failures);
            self::assertInstanceOf(\RuntimeException::class, $e->last());
            // 事件：2 次 RetryAttempting + 1 次 RetryExhausted
            self::assertCount(3, $this->events);
            self::assertInstanceOf(RetryAttempting::class, $this->events[0]);
            self::assertInstanceOf(RetryAttempting::class, $this->events[1]);
            self::assertInstanceOf(RetryExhaustedEvent::class, $this->events[2]);
            self::assertSame(3, $this->events[2]->attempts);
            self::assertSame([0.05, 0.05], $this->slept);
            throw $e;
        }
    }

    public function testRetryOnClassFilterStopsEarlyOnNonRetryable(): void
    {
        $r = $this->retry(new FixedBackoff(1.0));
        $this->expectException(RetryExhausted::class);

        try {
            $r->run(static function (): void {
                throw new \InvalidArgumentException('bad input');
            }, ['attempts' => 5, 'retryOn' => [\RuntimeException::class]]);
            self::fail('应抛出 RetryExhausted');
        } catch (RetryExhausted $e) {
            self::assertSame(1, $e->attempts, '不可重试异常应第 1 次就放弃');
            self::assertCount(0, $this->slept, '不可重试不应等待');
            self::assertCount(1, $this->events, '最终失败应派发 RetryExhausted');
            self::assertInstanceOf(RetryExhaustedEvent::class, $this->events[0]);
            throw $e;
        }
    }

    public function testRetryOnClassFilterRetriesOnlyMatching(): void
    {
        $r = $this->retry(new FixedBackoff(0.01));
        $n = 0;
        $result = $r->run(static function () use (&$n): string {
            $n++;
            if ($n < 2) {
                throw new \RuntimeException('transient');
            }

            return 'ok';
        }, ['attempts' => 3, 'retryOn' => [\RuntimeException::class]]);

        self::assertSame('ok', $result);
        self::assertCount(1, $this->slept);
    }

    public function testRetryOnCallableFilter(): void
    {
        $r = $this->retry(new FixedBackoff(0.01));
        $this->expectException(RetryExhausted::class);

        try {
            $r->run(static function (): void {
                throw new \RuntimeException('code 2', 2);
            }, [
                'attempts' => 3,
                'retryOn' => static fn (\Throwable $e): bool => $e->getCode() === 1,
            ]);
            self::fail('应抛出 RetryExhausted');
        } catch (RetryExhausted $e) {
            self::assertSame(1, $e->attempts, 'callable 判定不可重试应第 1 次就放弃');
            throw $e;
        }
    }

    public function testTimeoutBudgetStopsBeforeSleeping(): void
    {
        $r = $this->retry(new FixedBackoff(5.0));
        $this->expectException(RetryExhausted::class);

        try {
            $r->run(static function (): void {
                throw new \RuntimeException('down');
            }, ['attempts' => 5, 'timeout' => 0.001]);
            self::fail('应抛出 RetryExhausted');
        } catch (RetryExhausted $e) {
            self::assertSame(1, $e->attempts, '退避将超预算应立即停止');
            self::assertCount(0, $this->slept, '预算内不应睡眠');
            self::assertCount(1, $this->events, '最终失败应派发 RetryExhausted');
            self::assertInstanceOf(RetryExhaustedEvent::class, $this->events[0]);
            throw $e;
        }
    }

    public function testSleeperReceivesComputedDelay(): void
    {
        $slept = [];
        $r = $this->retry(new ExponentialBackoff(base: 0.1, factor: 2.0, cap: 10.0, jitter: false),
            static function (float $s) use (&$slept): void {
                $slept[] = $s;
            });

        $n = 0;
        $r->run(static function () use (&$n): string {
            $n++;
            if ($n < 3) {
                throw new \RuntimeException('x');
            }

            return 'done';
        }, ['attempts' => 3]);

        // 第1跳失败→等 0.1；第2跳失败→等 0.2；第3跳成功
        self::assertSame([0.1, 0.2], $slept);
    }
}
