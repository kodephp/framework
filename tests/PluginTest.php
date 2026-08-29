<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Application;
use Kode\Framework\Plugin\PluginManager;
use Kode\Http\App;
use Kode\Http\Routing\Route;
use Kode\Framework\Tests\Fixtures\Plugin\DemoPlugin;
use PHPUnit\Framework\TestCase;

/**
 * 插件机制（config/plugins.php + PluginManager）集成测试。
 *
 * 验证：插件可通过 PluginManager 注册路由（打 plugin:demo 来源标签）与绑定服务，
 * 且路由真实进入路由器、服务可从容器解析。
 */
final class PluginTest extends TestCase
{
    public function testPluginRegistersRouteAndService(): void
    {
        Application::make(\Kode\Framework\Tests\TestCase::SKELETON_ROOT);

        /** @var PluginManager $manager */
        $manager = resolve(PluginManager::class);
        $manager->load([DemoPlugin::class]);

        // 路由真实进入路由器。
        /** @var App $app */
        $app = resolve(App::class);
        $found = false;
        foreach ($app->getRouter()->getRoutes() as $route) {
            /** @var Route $route */
            if ($route->getPattern() === '/plugin/demo') {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, '插件路由未进入路由器');

        // 服务可从容器解析。
        /** @var \Kode\Framework\Tests\Fixtures\Plugin\DemoService $svc */
        $svc = $manager->make('demo.service');
        self::assertSame('hello from demo plugin', $svc->greet());

        // 插件已登记。
        self::assertArrayHasKey('demo', $manager->all());
    }
}
