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
        // 框架根目录自身含 app/config/src/public/bin，可作为监听目标。
        $this->root = dirname(__DIR__);
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
}
