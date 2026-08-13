<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Core\Config\Config;
use Kode\Framework\Config\ConfigCenter;
use Kode\Framework\Config\ConfigReloaded;
use Kode\Framework\Config\ConfigSource;
use Kode\Framework\Config\FileConfigSource;
use PHPUnit\Framework\TestCase;

/**
 * 配置中心薄壳层：ConfigSource / FileConfigSource / ConfigCenter 管理器（隔离单测）。
 *
 * 集成接线（Provider + 优先级 + 运行时 reload）见 ConfigCenterIntegrationTest。
 */
final class ConfigCenterTest extends TestCase
{
    public function testFileConfigSourceLoadsPhpArray(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cfg') . '.php';
        file_put_contents($file, "<?php return ['app' => ['name' => 'center'], 'feature' => ['beta' => true]];");

        $source = new FileConfigSource(['path' => $file, 'name' => 'file']);
        self::assertSame('file', $source->name());
        self::assertTrue($source->isReloadable());
        self::assertSame(['app' => ['name' => 'center'], 'feature' => ['beta' => true]], $source->load());

        unlink($file);
    }

    public function testFileConfigSourceLoadsJson(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cfg') . '.json';
        file_put_contents($file, (string) json_encode(['log' => ['level' => 'debug']]));

        $source = new FileConfigSource(['path' => $file]);
        self::assertSame(['log' => ['level' => 'debug']], $source->load());

        unlink($file);
    }

    public function testFileConfigSourceMissingFileReturnsEmpty(): void
    {
        $source = new FileConfigSource(['path' => '/no/such/file.php']);
        self::assertSame([], $source->load());
    }

    public function testFileConfigSourceUnsupportedExtensionThrows(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cfg') . '.yaml';
        file_put_contents($file, 'a: 1');

        $this->expectException(\RuntimeException::class);
        (new FileConfigSource(['path' => $file]))->load();

        unlink($file);
    }

    public function testSeedMergesSourcesIntoConfig(): void
    {
        $config = new Config();
        $config->set('app.name', 'file-value');
        $config->set('app.debug', false);

        $center = new ConfigCenter($config, [
            new class implements ConfigSource {
                public function name(): string { return 'remote'; }
                public function load(): array { return ['app' => ['name' => 'center-value'], 'new' => ['k' => 1]]; }
                public function isReloadable(): bool { return true; }
            },
        ]);

        $center->seed();

        // 中心值覆盖文件值
        self::assertSame('center-value', $config->get('app.name'));
        self::assertSame(['k' => 1], $config->get('new'));
        // 未被中心覆盖的键保持原值
        self::assertFalse($config->get('app.debug'));
    }

    public function testReloadReturnsChangedTopKeysAndDispatchesEvent(): void
    {
        $state = ['log' => ['level' => 'info']];
        $reloaded = null;

        $source = new class($state) implements ConfigSource {
            public function __construct(private array &$state) {}
            public function name(): string { return 'remote'; }
            public function load(): array { return $this->state; }
            public function isReloadable(): bool { return true; }
        };

        $center = new ConfigCenter(new Config(), [$source], function (object $e) use (&$reloaded): object {
            $reloaded = $e;

            return $e;
        });
        $center->seed();

        // 改变远程源内容后 reload
        $state['log']['level'] = 'debug';
        $state['app'] = ['name' => 'x'];
        $changed = $center->reload();

        self::assertSame(['log', 'app'], $changed);
        self::assertInstanceOf(ConfigReloaded::class, $reloaded);
        self::assertSame(['log', 'app'], $reloaded->changedKeys);
        self::assertNotNull($reloaded->reloadedAt);
    }

    public function testReloadSkipsNonReloadableSources(): void
    {
        $static = new class implements ConfigSource {
            public function name(): string { return 'static'; }
            public function load(): array { return ['static' => ['v' => 1]]; }
            public function isReloadable(): bool { return false; }
        };
        $dynamic = new class implements ConfigSource {
            public int $counter = 0;
            public function name(): string { return 'dyn'; }
            public function load(): array { $this->counter++; return ['dyn' => ['v' => $this->counter]]; }
            public function isReloadable(): bool { return true; }
        };

        $center = new ConfigCenter(new Config(), [$static, $dynamic]);
        $center->seed();
        self::assertSame(1, $dynamic->counter);

        // reload 不应再次调用静态源（无新内容），动态源被重新拉取
        $center->reload();
        self::assertSame(2, $dynamic->counter);
    }

    public function testStartedDisabledSourcesExposesDiagnostics(): void
    {
        $center = new ConfigCenter(new Config(), [
            new class implements ConfigSource {
                public function name(): string { return 'a'; }
                public function load(): array { return []; }
                public function isReloadable(): bool { return true; }
            },
        ]);
        $center->seed();

        self::assertSame(['a'], $center->sources());
        self::assertNull($center->lastReloadAt());
        self::assertSame([], $center->lastChangedKeys());
    }
}
