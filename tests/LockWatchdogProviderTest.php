<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Application;
use Kode\Framework\Lock\LockManager;
use Kode\Framework\Lock\LockWatchdog;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * 看门狗开关接线验证（真引导框架）。
 *
 * 修复前 config/lock.php 的 `watchdog.enabled` 无人读取——配成 false 也照样挂续期循环，
 * 属于「配置说关、运行时不关」的静默失接。本类断言该键确实贯通到 LockWatchdog。
 *
 * 各用例独立进程：Application 是进程级单例，配置须在用例内首次引导时固定。
 */
final class LockWatchdogProviderTest extends TestCase
{
    /**
     * @param array<string, mixed> $watchdog
     */
    private function boot(array $watchdog): void
    {
        if (app() === null) {
            Application::make(\Kode\Framework\Tests\TestCase::SKELETON_ROOT, [
                'lock' => ['watchdog' => $watchdog],
            ]);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRenewalEnabledByDefault(): void
    {
        $this->boot([]);

        self::assertTrue(
            app()->container->get(LockWatchdog::class)->renewalEnabled(),
            '未配置时应默认启用自动续期',
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConfigDisablesRenewal(): void
    {
        $this->boot(['enabled' => false]);

        self::assertFalse(
            app()->container->get(LockWatchdog::class)->renewalEnabled(),
            'watchdog.enabled=false 必须关闭自动续期',
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testProtectStillHoldsAndReleasesWhenRenewalDisabled(): void
    {
        // 关的是「自动续期」这道保险，不是锁本身：加锁/执行/释放语义必须不变。
        $this->boot(['enabled' => false]);

        /** @var LockWatchdog $wd */
        $wd = app()->container->get(LockWatchdog::class);
        /** @var LockManager $manager */
        $manager = app()->container->get(LockManager::class);

        $heldDuringWork = null;
        self::assertSame('ok', $wd->protect('wd:protect', function () use ($manager, &$heldDuringWork): string {
            $heldDuringWork = $manager->isLocked('wd:protect');

            return 'ok';
        }, 20));
        self::assertTrue($heldDuringWork, '禁用续期后 protect 仍须持锁执行业务');
        self::assertFalse($manager->isLocked('wd:protect'), '业务结束后必须释放');
    }
}
