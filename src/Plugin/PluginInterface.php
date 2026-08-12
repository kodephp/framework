<?php

declare(strict_types=1);

namespace Kode\Framework\Plugin;

use Kode\Core\Container;

/**
 * 插件契约（对齐 webman 的插件机制，但更轻量）
 *
 * 一个插件是一个实现本接口的类，在 config/plugins.php 的 plugins 数组里声明。
 * 框架在启动期实例化并依次调用 register() / boot()：
 *  - register()：绑定服务、注册路由/监听器/命令（此时容器与路由已就绪）；
 *  - boot()：插件启动逻辑（如预热缓存、注册定时任务）。
 *
 * 插件通过 {@see PluginManager} 的薄接口完成上述注册，无需关心框架内部接线。
 */
interface PluginInterface
{
    /**
     * 插件名（用于路由来源标签、日志，如 'blog'）。
     */
    public function name(): string;

    /**
     * 注册阶段：绑定服务 / 路由 / 监听器 / 命令。
     */
    public function register(PluginManager $manager): void;

    /**
     * 启动阶段：插件自身初始化逻辑。
     */
    public function boot(PluginManager $manager): void;
}
