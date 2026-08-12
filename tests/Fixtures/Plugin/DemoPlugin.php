<?php

declare(strict_types=1);

namespace Kode\Framework\Tests\Fixtures\Plugin;

use Kode\Framework\Plugin\PluginInterface;
use Kode\Framework\Plugin\PluginManager;

/**
 * 测试用示例插件：注册一条路由 + 绑定一个服务，验证插件机制真实生效。
 */
final class DemoPlugin implements PluginInterface
{
    public function name(): string
    {
        return 'demo';
    }

    public function register(PluginManager $manager): void
    {
        $manager->addRoute('demo.hello', 'GET', '/plugin/demo', static fn(): array => [
            'plugin' => 'demo',
            'hello' => 'world',
        ]);

        $manager->bind('demo.service', static fn(): DemoService => new DemoService());
    }

    public function boot(PluginManager $manager): void
    {
    }
}
