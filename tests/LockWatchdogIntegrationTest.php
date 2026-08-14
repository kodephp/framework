<?php

declare(strict_types=1, ticks=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Lock\LockManager;
use Kode\Framework\Lock\LockWatchdog;
use Kode\Framework\Lock\LockWatchdogRenewed;
use Kode\Framework\Lock\StaticLockManager;
use PHPUnit\Framework\TestCase;

/**
 * 看门狗集成测试：使用内置 tick 驱动（register_tick_function），长任务期间验证自动续期。
 *
 * 模拟「长任务执行时间超过 TTL」的真实场景：看门狗应在 work 运行期间持续刷新 TTL，
 * 避免锁过期；work 结束后立即释放。
 */
final class LockWatchdogIntegrationTest extends TestCase
{
    public function testLongRunningWorkKeepsLockViaRenewalThenReleases(): void
    {
        $manager = new StaticLockManager();
        $events = [];
        $dispatcher = static function (object $e) use (&$events): void {
            $events[] = $e;
        };

        // ttl=5 → 续期间隔 = ceil(5 * 0.34) = 2s；work 运行约 3s，期间应至少续期一次。
        $wd = new LockWatchdog($manager, renewRatio: 0.34, driver: 'tick', dispatcher: $dispatcher);

        $heldDuringWork = null;
        $result = $wd->protect('long', function () use ($manager, &$heldDuringWork): string {
            $heldDuringWork = $manager->isLocked('long');
            // 约 3s 的语句边界密集循环，触发 tick 续期
            for ($i = 0; $i < 15; ++$i) {
                usleep(200_000);
            }

            return 'done';
        }, 5);

        self::assertSame('done', $result);
        self::assertTrue($heldDuringWork, 'work 执行期间锁应保持持有');
        self::assertFalse($manager->isLocked('long'), 'work 结束后锁应释放');

        $renewed = array_values(array_filter($events, static fn ($e): bool => $e instanceof LockWatchdogRenewed));
        self::assertNotEmpty($renewed, '长任务期间应发生至少一次续期');
        // 续期后剩余 TTL 应被刷新回接近原 ttl=5（远未过期）
        self::assertGreaterThan(2, $renewed[array_key_first($renewed)]?->remaining ?? 0);
    }

    public function testShortWorkDoesNotNeedRenewal(): void
    {
        $manager = new StaticLockManager();
        $events = [];
        $dispatcher = static function (object $e) use (&$events): void {
            $events[] = $e;
        };

        // ttl=5，续期间隔 2s，但 work 瞬时完成（< interval）→ 不应续期。
        $wd = new LockWatchdog($manager, renewRatio: 0.34, driver: 'tick', dispatcher: $dispatcher);
        $result = $wd->protect('short', static fn (): int => 7, 5);

        self::assertSame(7, $result);
        self::assertFalse($manager->isLocked('short'));
        $renewed = array_filter($events, static fn ($e): bool => $e instanceof LockWatchdogRenewed);
        self::assertEmpty($renewed, '短任务不应触发续期');
    }
}
