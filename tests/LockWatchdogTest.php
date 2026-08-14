<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Lock\LockAcquireException;
use Kode\Framework\Lock\LockManager;
use Kode\Framework\Lock\LockWatchdog;
use Kode\Framework\Lock\LockWatchdogRenewed;
use Kode\Framework\Lock\LockWatchdogStarted;
use Kode\Framework\Lock\LockWatchdogStopped;
use Kode\Framework\Lock\StaticLockManager;
use PHPUnit\Framework\TestCase;

/**
 * 分布式锁看门狗单元测试（注入可控 ticker，确定性验证续期编排）。
 *
 * 续期复用 {@see LockManager::acquire()} 同 owner 重入刷新 TTL；看门狗绝不会续期他人持有的锁。
 */
final class LockWatchdogTest extends TestCase
{
    /**
     * protect 透传业务返回值，且工作结束后锁被释放。
     */
    public function testProtectReturnsWorkValueAndReleasesLock(): void
    {
        $manager = new StaticLockManager();
        $wd = new LockWatchdog($manager, ticker: $this->passthroughTicker());

        $result = $wd->protect('k', static fn (): string => 'payload', 30);

        self::assertSame('payload', $result);
        self::assertFalse($manager->isLocked('k'), '工作结束后锁应释放');
    }

    /**
     * 续期循环在 work 运行期间刷新 TTL，并派发 LockWatchdogRenewed 事件（count 从 1 递增）。
     */
    public function testWatchdogRenewsTtlAndDispatchesEvent(): void
    {
        $manager = new StaticLockManager();
        $events = [];
        $dispatcher = static function (object $e) use (&$events): void {
            $events[] = $e;
        };

        $renewedTtl = null;
        $ticker = function (callable $work, callable $tick) use ($manager, &$renewedTtl): mixed {
            $result = $work();
            $tick(); // 模拟一次续期
            $renewedTtl = $manager->ttl('k');

            return $result;
        };

        $wd = new LockWatchdog($manager, renewRatio: 0.34, dispatcher: $dispatcher, ticker: $ticker);
        $wd->protect('k', static fn (): int => 42, 10);

        // 续期后 TTL 应被刷新（接近原 ttl=10，而非接近 0）
        self::assertNotNull($renewedTtl);
        self::assertGreaterThan(5, $renewedTtl, '续期应将剩余 TTL 刷新回接近原 ttl');

        $started = array_filter($events, static fn ($e): bool => $e instanceof LockWatchdogStarted);
        $renewed = array_filter($events, static fn ($e): bool => $e instanceof LockWatchdogRenewed);
        $stopped = array_filter($events, static fn ($e): bool => $e instanceof LockWatchdogStopped);
        self::assertCount(1, $started);
        self::assertCount(1, $renewed);
        self::assertCount(1, $stopped);
        self::assertSame(1, $renewed[array_key_first($renewed)]?->count);
        self::assertSame(1, $stopped[array_key_first($stopped)]?->renews);
    }

    /**
     * 续期仅当 owner 匹配时生效——显式 owner 时，Renewed 事件记录的 owner 必须与 protect 一致。
     */
    public function testRenewUsesProvidedOwner(): void
    {
        $manager = new StaticLockManager();
        $events = [];
        $dispatcher = static function (object $e) use (&$events): void {
            $events[] = $e;
        };

        $ticker = function (callable $work, callable $tick): mixed {
            $result = $work();
            $tick();

            return $result;
        };

        $wd = new LockWatchdog($manager, dispatcher: $dispatcher, ticker: $ticker);
        $wd->protect('k', static fn (): int => 1, 30, 'owner-abc');

        $renewed = array_values(array_filter($events, static fn ($e): bool => $e instanceof LockWatchdogRenewed));
        self::assertSame('owner-abc', $renewed[0]?->owner);
    }

    /**
     * work 抛出异常时，finally 仍释放锁，且续期不发生（work 在 tick 之前抛错）。
     */
    public function testWorkExceptionStillReleasesAndSkipsRenew(): void
    {
        $manager = new StaticLockManager();
        $events = [];
        $dispatcher = static function (object $e) use (&$events): void {
            $events[] = $e;
        };

        $ticker = function (callable $work, callable $tick): mixed {
            try {
                return $work();
            } finally {
                // work 抛异常时不应续期
            }
        };

        $wd = new LockWatchdog($manager, dispatcher: $dispatcher, ticker: $ticker);

        $this->expectException(\RuntimeException::class);
        try {
            $wd->protect('k', static function (): int {
                throw new \RuntimeException('boom');
            }, 30);
        } finally {
            self::assertFalse($manager->isLocked('k'), '异常后锁应释放');
            $renewed = array_filter($events, static fn ($e): bool => $e instanceof LockWatchdogRenewed);
            self::assertEmpty($renewed, 'work 抛异常时不应续期');
            $stopped = array_filter($events, static fn ($e): bool => $e instanceof LockWatchdogStopped);
            self::assertCount(1, $stopped);
        }
    }

    /**
     * 锁已被其他 owner 持有时，protect 获取失败抛 LockAcquireException，work 不执行。
     */
    public function testProtectThrowsWhenAlreadyHeldByAnotherOwner(): void
    {
        $manager = new StaticLockManager();
        // 模拟「他人」持有：使用与 protect 不同的默认 owner 抢占
        self::assertTrue($manager->acquire('k', 30));

        $executed = false;
        $ticker = function (callable $work): mixed {
            return $work();
        };

        $wd = new LockWatchdog($manager, ticker: $ticker);

        $caught = null;
        try {
            $wd->protect('k', static function () use (&$executed): int {
                $executed = true;

                return 1;
            }, 30);
            self::fail('应抛出 LockAcquireException');
        } catch (LockAcquireException $e) {
            $caught = $e;
        }

        self::assertInstanceOf(LockAcquireException::class, $caught);
        self::assertFalse($executed, '获取失败不应执行 work');
    }

    /**
     * 提供一个 passthrough ticker（直接执行 work，不续期），用于基础透传/释放测试。
     */
    private function passthroughTicker(): \Closure
    {
        return function (callable $work, callable $tick, int $interval): mixed {
            unset($tick, $interval);

            return $work();
        };
    }
}
