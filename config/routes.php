<?php

/*
 * 路由来源配置
 *
 * 框架支持两套并存的路由模型：
 *  1) 属性路由（约定优于配置）：在控制器类/方法上用 #[Controller]/#[Get] 等声明，
 *     启动时自动扫描注册——「多应用自动路由匹配」。
 *  2) 显式路由（routes.php）：手动注册、可命名、可覆盖同名路径。
 *
 * route:list 默认只列主应用（无插件）。开发者开启 discover_plugins 后，
 * plugins/<name>/routes.php 与 plugins/<name>/src/Controllers 才被纳入，成为自定义来源。
 */

return [
    // 属性路由扫描开关与目录（key=来源标签，value=控制器目录）。
    'attributes' => [
        'enabled' => (bool) env('ROUTE_ATTRIBUTES', true),
        'controllers' => [
            'app' => base_path('app/Http/Controllers'),
            // 'admin' => base_path('modules/admin/Controllers'),
        ],
    ],

    // 额外的显式路由文件（key=来源标签，value=文件路径）。
    'sources' => [
        // 'admin' => base_path('modules/admin/routes.php'),
    ],

    // 插件自动发现（默认关闭；开启后扫描 plugins/<name> 作为独立模块来源）。
    'discover_plugins' => (bool) env('ROUTES_DISCOVER_PLUGINS', false),
];
