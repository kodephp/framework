<?php

/*
 * 插件配置（对齐 webman 的插件机制）
 *
 * plugins 数组放「实现了 Kode\Framework\Plugin\PluginInterface 的类名」。
 * 框架启动期会实例化并依次调用其 register() / boot()：
 *  - register()：绑定服务、注册路由/监听器/命令；
 *  - boot()：插件自身初始化。
 *
 * 插件路由会自动打上 plugin:<name> 来源标签，可用 `bin/kode route:list --source=plugin:blog` 查看。
 */

return [
    'plugins' => [
        // 示例：
        // \App\Plugins\BlogPlugin::class,
    ],
];
