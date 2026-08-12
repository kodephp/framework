<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Resilience\Breaker;
use Kode\Framework\Resilience\CircuitOpenException;
use Kode\Framework\Resilience\FiberBreaker;
use Kode\Fibers\Core\CircuitBreaker as FiberCircuitBreaker;
use PHPUnit\Framework\TestCase;

/**
 * 熔断器单元测试：直接构造 Breaker（注入 FiberBreaker 引擎工厂，算法委托 kode/fibers），
 * 验证成功放行、失败熔断、降级 fallback 与状态机语义。
 */
final class BreakerTest extends TestCase
{
    private function makeBreaker(int $threshold = 2, float $timeout = 1.0): Breaker
    {
        $factory = static fn(string $name, array $config): FiberBreaker => new FiberBreaker(
            new FiberCircuitBreaker(
                failureThreshold: $threshold,
                recoveryTimeout: $timeout,
                halfOpenMaxCalls: 1,
            )
        );

        return new Breaker([], $factory);
    }

    /**
     * 触发一次失败：任务异常原样抛出（fallback 不接住首次失败），
     * 熔断器在此调用后进入 open（取决于阈值）。
     */
    private function trip(Breaker $breaker, string $name): void
    {
        try {
            $breaker->run($name, static fn() => throw new \RuntimeException('boom'), fallback: static fn() => null);
        } catch (\RuntimeException $e) {
            // 首次失败：异常传播，熔断器打开
        }
    }

    public function testRunReturnsTaskResultWhenClosed(): void
    {
        $breaker = $this->makeBreaker();

        $result = $breaker->run('svc', static fn() => 'ok');

        self::assertSame('ok', $result);
        self::assertSame('closed', $breaker->state('svc'));
    }

    public function testRunFallsBackWhenOpenAndFallbackProvided(): void
    {
        $breaker = $this->makeBreaker(threshold: 1);

        $this->trip($breaker, 'svc');
        self::assertSame('open', $breaker->state('svc'));

        // 再次调用：熔断器已开，直接走 fallback
        $result = $breaker->run('svc', static fn() => 'ok', fallback: static fn() => 'degraded');
        self::assertSame('degraded', $result);
    }

    public function testRunThrowsWhenOpenAndNoFallback(): void
    {
        $breaker = $this->makeBreaker(threshold: 1);
        $this->trip($breaker, 'svc');
        self::assertSame('open', $breaker->state('svc'));

        $this->expectException(CircuitOpenException::class);
        $breaker->run('svc', static fn() => 'ok');
    }

    public function testHalfOpenRecoversAfterTimeout(): void
    {
        $breaker = $this->makeBreaker(threshold: 1, timeout: 0.2);
        $this->trip($breaker, 'svc');
        self::assertSame('open', $breaker->state('svc'));

        usleep(300_000); // 超过恢复窗口

        // 半开后探活成功 → 回到 closed
        $result = $breaker->run('svc', static fn() => 'recovered');
        self::assertSame('recovered', $result);
        self::assertSame('closed', $breaker->state('svc'));
    }
}
