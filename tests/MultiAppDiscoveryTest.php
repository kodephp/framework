<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Core\Config\Config;
use Kode\Framework\Providers\HttpServiceProvider;
use Kode\DI\Contract\ContainerInterface;
use PHPUnit\Framework\TestCase;

/**
 * 多应用自动发现（零开关）单元测试。
 *
 * 验证：
 *  - resolveRouteSources()：app/{App}/routes.php 与 app/{App}/routes/*.php 自动入表，
 *    带 app:<App> 标签；不含 routes.php 的普通目录（app/services 等）不被误判；
 *    主应用 app/routes.php 与 app/routes/*.php 行为不变。
 *  - withApplicationControllerDirs()：子应用 http/controllers 与 controllers 自动纳入，
 *    同样不误伤普通目录。
 */
final class MultiAppDiscoveryTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/kode-multiapp-' . bin2hex(random_bytes(6));
        $base = $this->tmp . '/app';

        foreach ([
            'routes',
            'admin/routes',
            'admin/http/controllers',
            'admin2/controllers',
            'services',   // 普通目录：无 routes.php，不应被识别为子应用
            'models',     // 普通目录
        ] as $rel) {
            mkdir($base . '/' . $rel, 0777, true);
        }

        file_put_contents($base . '/routes.php', '<?php return static fn($app) => null;');
        file_put_contents($base . '/routes/api.php', '<?php return static fn($app) => null;');
        file_put_contents($base . '/admin/routes.php', '<?php return static fn($app) => null;');
        file_put_contents($base . '/admin/routes/panel.php', '<?php return static fn($app) => null;');
        file_put_contents($base . '/admin2/routes.php', '<?php return static fn($app) => null;');
        file_put_contents($base . '/services/Foo.php', '<?php namespace app\\services;');
        // 控制器夹具：真实声明与目录对应的 namespace。
        file_put_contents(
            $base . '/admin/http/controllers/AdminPanelController.php',
            "<?php\nnamespace app\\admin\\http\\controllers;\nfinal class AdminPanelController {}\n"
        );
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    private function makeProvider(): HttpServiceProvider
    {
        $config = new Config();
        $config->set('path.base', $this->tmp);
        $config->set('routes.sources', ['extra' => $this->tmp . '/app/routes/extra.php']);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn(string $id): bool => $id === Config::class
        );
        $container->method('make')->willReturnCallback(
            static fn(string $id): mixed => $id === Config::class ? $config : null
        );

        return new HttpServiceProvider($container);
    }

    private function invoke(string $method): mixed
    {
        $ref = new \ReflectionMethod(HttpServiceProvider::class, $method);

        return $ref->invoke($this->makeProvider());
    }

    private function invokeWith(string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod(HttpServiceProvider::class, $method);

        return $ref->invoke($this->makeProvider(), ...$args);
    }

    public function testResolveRouteSourcesDiscoversSubApplications(): void
    {
        /** @var array<string, string> $sources */
        $sources = $this->invoke('resolveRouteSources');

        // 主应用行为不变。
        self::assertSame($this->tmp . '/app/routes.php', $sources['app']);
        self::assertSame($this->tmp . '/app/routes/api.php', $sources['routes:api']);

        // 子应用自动发现。
        self::assertSame($this->tmp . '/app/admin/routes.php', $sources['app:admin']);
        self::assertSame($this->tmp . '/app/admin/routes/panel.php', $sources['app:admin:panel']);
        self::assertSame($this->tmp . '/app/admin2/routes.php', $sources['app:admin2']);

        // 普通目录不被误判为子应用。
        self::assertArrayNotHasKey('app:services', $sources);
        self::assertArrayNotHasKey('app:models', $sources);

        // config 声明的额外来源仍在。
        self::assertSame($this->tmp . '/app/routes/extra.php', $sources['extra']);
    }

    public function testWithApplicationControllerDirsAddsSubAppDirs(): void
    {
        /** @var array<string, string> $dirs */
        $dirs = $this->invokeWith('withApplicationControllerDirs', []);

        self::assertSame(
            $this->tmp . '/app/admin/http/controllers',
            $dirs['app:admin'] ?? null
        );
        self::assertSame(
            $this->tmp . '/app/admin2/controllers',
            $dirs['app:admin2'] ?? null
        );
        self::assertArrayNotHasKey('app:services', $dirs);
        self::assertArrayNotHasKey('app:models', $dirs);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}