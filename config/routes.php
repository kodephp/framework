<?php

/*
 * 路由来源配置
 *
 * 框架默认加载 app/routes.php（来源标签 `app`）。本文件用于声明「额外路由来源」
 * 与「插件自动发现」，使 `bin/kode console route:list` 能按来源/文件聚合，
 * 大项目或插件化开发时也能一眼看清每条路由来自哪里。
 *
 * sources：key 为来源标签，value 为路由文件路径（支持 base_path()）。
 * discover_plugins：为 true 时，自动扫描 plugins/<name>/routes.php 并登记为
 *   `plugin:<name>` 来源（每个插件是独立模块，路由互不耦合）。
 */

return [
    'sources' => [
        // 'admin' => base_path('modules/admin/routes.php'),
    ],

    'discover_plugins' => (bool) env('ROUTES_DISCOVER_PLUGINS', false),
];
