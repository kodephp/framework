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
 *  - withApplicationControllerDirs()：子应用 controllers（新约定，无 http 层）优先、
 *    http/controllers（旧写法）兼容，同样不误伤普通目录。
 *  - withConventionControllerDirs()：单应用约定兜底 —— app/controllers 优先、
 *    app/http/controllers 兼容；配置显式声明时以配置为准。
 *  - ConsoleServiceProvider::discoverCommandClasses()：主应用 app/console 与
 *    子应用 app/{App}/console 的命令类名推导。
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
            'admin/http/controllers',          // 旧写法子应用（兼容）
            'admin2/controllers',              // 新约定子应用
            'admin3/controllers',              // 两种目录都有：新约定优先
            'admin3/http/controllers',
            'both/routes',                     // 无控制器目录的子应用（只测路由来源）
            'services',   // 普通目录：无 routes.php，不应被识别为子应用
            'models',     // 普通目录
            'console',    // 主应用命令目录
        ] as $rel) {
            mkdir($base . '/' . $rel, 0777, true);
        }

        file_put_contents($base . '/routes.php', '<?php return static fn($app) => null;');
        file_put_contents($base . '/routes/api.php', '<?php return static fn($app) => null;');
        file_put_contents($base . '/admin/routes.php', '<?php return static fn($app) => null;');
        file_put_contents($base . '/admin/routes/panel.php', '<?php return static fn($app) => null;');
        file_put_contents($base . '/admin2/routes.php', '<?php return static fn($app) => null;');
        file_put_contents($base . '/admin3/routes.php', '<?php return static fn($app) => null;');
        file_put_contents($base . '/both/routes.php', '<?php return static fn($app) => null;');
        file_put_contents($base . '/services/Foo.php', '<?php namespace app\\services;');
        // 控制器夹具：真实声明与目录对应的 namespace。
        file_put_contents(
            $base . '/admin/http/controllers/AdminPanelController.php',
            "<?php\nnamespace app\\admin\\http\\controllers;\nfinal class AdminPanelController {}\n"
        );
        file_put_contents(
            $base . '/admin3/controllers/BothAwareController.php',
            "<?php\nnamespace app\\admin3\\controllers;\nfinal class BothAwareController {}\n"
        );
        // 命令夹具：主应用 + 子应用各一份；无 routes.php 的目录（services）不算子应用。
        file_put_contents($base . '/console/GreetCommand.php', '<?php');
        mkdir($base . '/admin/console', 0777, true);
        file_put_contents($base . '/admin/console/SyncFeedCommand.php', '<?php');
        mkdir($base . '/services/console', 0777, true);
        file_put_contents($base . '/services/console/ShouldNotScanCommand.php', '<?php');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    private function makeProvider(?array $sources = null): HttpServiceProvider
    {
        $config = new Config();
        $config->set('path.base', $this->tmp);
        $config->set('routes.sources', $sources ?? ['extra' => $this->tmp . '/app/routes/extra.php']);

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

    public function testRouteSourceRelativeToProjectBaseIsResolved(): void
    {
        // 配置注释的示例写法 base_path('app/routes/x.php') 在引导期退化成 CWD 相对路径；
        // 若不按 path.base 拼接，从别的工作目录启动会静默加载 0 条路由（无警告）。
        $ref = new \ReflectionMethod(HttpServiceProvider::class, 'resolveRouteSources');
        /** @var array<string, string> $sources */
        $sources = $ref->invoke($this->makeProvider(['rel' => 'app/routes/extra.php']));

        self::assertSame($this->tmp . '/app/routes/extra.php', $sources['rel']);
    }

    public function testWithApplicationControllerDirsAddsSubAppDirs(): void
    {
        /** @var array<string, string> $dirs */
        $dirs = $this->invokeWith('withApplicationControllerDirs', []);

        // 旧写法 http/controllers（无新约定目录时）仍然被发现。
        self::assertSame(
            $this->tmp . '/app/admin/http/controllers',
            $dirs['app:admin'] ?? null
        );
        self::assertSame(
            $this->tmp . '/app/admin2/controllers',
            $dirs['app:admin2'] ?? null
        );
        // 两种目录并存时新约定优先。
        self::assertSame(
            $this->tmp . '/app/admin3/controllers',
            $dirs['app:admin3'] ?? null
        );
        self::assertArrayNotHasKey('app:services', $dirs);
        self::assertArrayNotHasKey('app:models', $dirs);
    }

    public function testWithConventionControllerDirsPrefersFlatControllers(): void
    {
        $base = $this->tmp;
        mkdir($base . '/app/controllers', 0777, true); // 新约定与旧写法并存 → 新约定优先

        $ref = new \ReflectionMethod(HttpServiceProvider::class, 'withConventionControllerDirs');
        /** @var array<string, string> $dirs */
        $dirs = $ref->invoke($this->makeProvider(), [], $base);

        self::assertSame($base . '/app/controllers', $dirs['app'] ?? null);
    }

    public function testWithConventionControllerDirsFallsBackToLegacyHttpDir(): void
    {
        $legacyBase = sys_get_temp_dir() . '/kode-legacy-' . bin2hex(random_bytes(4));
        mkdir($legacyBase . '/app/http/controllers', 0777, true);

        try {
            $ref = new \ReflectionMethod(HttpServiceProvider::class, 'withConventionControllerDirs');
            /** @var array<string, string> $dirs */
            $dirs = $ref->invoke($this->makeProvider(), [], $legacyBase);

            self::assertSame($legacyBase . '/app/http/controllers', $dirs['app'] ?? null, '旧项目零配置仍可扫描');
        } finally {
            $this->rrmdir($legacyBase);
        }
    }

    public function testWithConventionControllerDirsYieldsToExplicitConfig(): void
    {
        $base = $this->tmp;
        mkdir($base . '/app/controllers', 0777, true);

        $ref = new \ReflectionMethod(HttpServiceProvider::class, 'withConventionControllerDirs');
        $configured = ['app' => $base . '/custom/ctrl'];
        /** @var array<string, string> $dirs */
        $dirs = $ref->invoke($this->makeProvider(), $configured, $base);

        self::assertSame($base . '/custom/ctrl', $dirs['app'] ?? null, '配置显式声明时约定兜底不动它');
    }

    public function testDiscoverCommandClassesCoversMainAndSubApps(): void
    {
        $provider = new \Kode\Framework\Providers\ConsoleServiceProvider($this->mockContainer());
        $ref = new \ReflectionMethod(\Kode\Framework\Providers\ConsoleServiceProvider::class, 'discoverCommandClasses');

        /** @var list<string> $classes */
        $classes = $ref->invoke($provider);

        self::assertContains('\app\console\GreetCommand', $classes);
        self::assertContains('\app\admin\console\SyncFeedCommand', $classes, '子应用命令自动发现');
        self::assertNotContains('\app\services\console\ShouldNotScanCommand', $classes, '非子应用目录不扫描');
    }

    private function mockContainer(): ContainerInterface
    {
        $config = new Config();
        $config->set('path.base', $this->tmp);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn(string $id): bool => $id === Config::class
        );
        $container->method('make')->willReturnCallback(
            static fn(string $id): mixed => $id === Config::class ? $config : null
        );

        return $container;
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