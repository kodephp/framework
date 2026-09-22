<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use Kode\Framework\Server\HttpServer;

/**
 * 启动横幅里的停止命令，以及「提示的入口是否真的存在」。
 *
 * 横幅曾写作 `php bin/kode stop`，控制台命令的用法段与两行运行时回执也遍布 bin/kode：
 * 本包 composer.json 的 bin 是仓库根的 kode（安装后为 vendor/bin/kode），项目根入口是骨架的
 * kode 薄壳，都不存在 bin/kode。照提示执行只会得到「Could not open input file」。
 * 且多实例按端口分片，stop 不带端口会作用到默认实例，用户停不掉刚拉起的那个。
 */
final class BannerStopHintTest extends TestCase
{
    /** 唯一允许提到旧入口的文件：显式探测老项目路径的兼容回退。 */
    private const LEGACY_ENTRY_ALLOWLIST = ['src/Server/HotReloadWatcher.php'];

    public function testStopHintCarriesPortAndRealEntry(): void
    {
        self::assertSame(
            'Input "php kode stop --port 9527" to stop. Start success.',
            HttpServer::stopHint(9527)
        );
    }

    /** 提示里的 flag 必须真是 CLI 支持的：帮助文本的 stop 行带 --port。 */
    public function testStopHintFlagExistsInCliHelp(): void
    {
        $cli = (string) file_get_contents(dirname(__DIR__) . '/kode');

        self::assertMatchesRegularExpression('/kode stop \[[^\n]*--port[^\n]*\]/', $cli);
    }

    /** 守护模式横幅真的用上 stopHint（而非写死字符串）；前台模式不提 stop。 */
    public function testDaemonBannerUsesStopHintWithItsPort(): void
    {
        $daemon = $this->banner([
            'listen' => '127.0.0.1:9599',
            'host' => '127.0.0.1',
            'port' => 9599,
            'workers' => 4,
            'root' => '/tmp/kode-app',
            'name' => 'kode-http',
            'daemon' => true,
            'debug' => false,
            'pid_file' => '/tmp/kode-app/storage/runtime/9599/kode.pid',
        ]);

        self::assertStringContainsString('Input "php kode stop --port 9599" to stop.', $daemon);
        self::assertStringContainsString('PID 文件：/tmp/kode-app/storage/runtime/9599/kode.pid', $daemon);
        // 守护模式下终端已脱离，再教用户按 Ctrl+C 是误导。
        self::assertStringNotContainsString('Press Ctrl+C', $daemon);

        $foreground = $this->banner([
            'listen' => '127.0.0.1:9527',
            'host' => '127.0.0.1',
            'port' => 9527,
            'workers' => 11,
            'root' => '/tmp/kode-app',
            'name' => 'kode-http',
            'daemon' => false,
            'debug' => true,
        ]);

        self::assertStringContainsString('Press Ctrl+C to stop. Start success.', $foreground);
        self::assertStringNotContainsString('php kode stop', $foreground);
    }

    /** 源码与 README 不得再把用户指向不存在的 bin/kode（vendor/bin/kode 是 composer 的真路径）。 */
    public function testDocsAndCodeDoNotPointAtPhantomEntry(): void
    {
        $root = dirname(__DIR__);
        $targets = [$root . '/README.md'];

        $walker = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/src', RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($walker as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $targets[] = $file->getPathname();
            }
        }

        $offenders = [];
        foreach ($targets as $path) {
            $relative = str_replace($root . '/', '', $path);
            if (in_array($relative, self::LEGACY_ENTRY_ALLOWLIST, true)) {
                continue;
            }
            $text = str_replace('vendor/bin/kode', '', (string) file_get_contents($path));
            if (str_contains($text, 'bin/kode')) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, '以下文件引用了不存在的入口 bin/kode');
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function banner(array $ctx): string
    {
        $method = new ReflectionMethod(HttpServer::class, 'renderBanner');
        $method->setAccessible(true);

        // 横幅的三个辅助方法（runtimeLabel/loopLabel/currentUser）不吃实例状态，无需真实构造。
        return (string) $method->invoke(
            (new ReflectionClass(HttpServer::class))->newInstanceWithoutConstructor(),
            null,
            $ctx
        );
    }
}
