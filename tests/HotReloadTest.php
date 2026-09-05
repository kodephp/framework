<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Server\HotReloadWatcher;
use PHPUnit\Framework\TestCase;

/**
 * 热重载看门狗单元测试（仅验证纯逻辑，不实际拉起 serve 子进程）。
 */
final class HotReloadTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        // 自建临时项目根（含 app/config/src/public/bin），使本用例不依赖仓库自身布局
        // ——仓库根自 v1.0.0 起收敛为纯内核，已不再携带 app/config/public。
        $this->root = sys_get_temp_dir() . '/kode_watch_' . uniqid('', true);
        foreach (['app', 'config', 'src', 'public', 'bin'] as $sub) {
            mkdir($this->root . '/' . $sub, 0o755, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testResolveWatchDirsFallsBackToExistingDefaults(): void
    {
        $dirs = (new HotReloadWatcher($this->root))->resolveWatchDirs();

        // 应回退到 root 下真实存在的默认子目录，且不含不存在的。
        self::assertContains($this->root . '/app', $dirs);
        self::assertContains($this->root . '/config', $dirs);
        self::assertContains($this->root . '/src', $dirs);
        foreach ($dirs as $dir) {
            self::assertDirectoryExists($dir);
        }
    }

    public function testResolveWatchDirsHonorsExplicitDirs(): void
    {
        $explicit = [$this->root . '/app'];
        $dirs = (new HotReloadWatcher($this->root, [], $explicit))->resolveWatchDirs();

        self::assertSame($explicit, $dirs);
    }

    public function testConstructedServeArgsAreForwardedWithoutWatch(): void
    {
        // 通过反射确认透传参数构造正确（子进程 serve 不应带 --watch）。
        $watcher = new HotReloadWatcher($this->root, ['--port', '9599']);
        $ref = new \ReflectionClass($watcher);
        $prop = $ref->getProperty('serveArgs');
        $prop->setAccessible(true);

        self::assertSame(['--port', '9599'], $prop->getValue($watcher));
    }

    public function testFastExitCounterTripsAfterConsecutiveCrashes(): void
    {
        // 连续秒崩累计，达到熔断阈值；中间跑稳一次即清零。
        $n = 0;
        $n = HotReloadWatcher::countFastExit($n, 0.5);
        $n = HotReloadWatcher::countFastExit($n, 1.2);
        self::assertSame(2, $n);

        $n = HotReloadWatcher::countFastExit($n, 30.0);
        self::assertSame(0, $n, '跑稳过的实例不应计入崩溃熔断');
    }
}
