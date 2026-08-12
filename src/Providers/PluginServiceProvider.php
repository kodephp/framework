<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Plugin\PluginManager;
use Kode\Framework\Providers\ServiceProvider;

/**
 * 插件服务提供者（对齐 webman 的插件机制，轻量实现）
 *
 * 读取 config/plugins.php 的 plugins 列表，交给 PluginManager 顺序加载。
 * 插件可注册服务/路由/监听器/命令，路由会打上 plugin:<name> 来源标签。
 */
final class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(PluginManager::class, fn(): PluginManager => new PluginManager($this->container));
    }

    public function boot(): void
    {
        /** @var PluginManager $manager */
        $manager = $this->container->get(PluginManager::class);

        /** @var array<int, class-string> $plugins */
        $plugins = (array) $this->config('plugins.plugins', []);
        if ($plugins !== []) {
            $manager->load($plugins);
        }
    }
}
