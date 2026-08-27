<?php

declare(strict_types=1);

/*
 * 路由配置
 *
 * - attributes：开启「注解路由」后，框架会扫描下列 controllers 目录，
 *   自动把 #[Controller] / #[Get] / #[Post] 等注解注册成路由（类似 Hyperf 的注解路由）。
 * - scan：闭包路由文件所在目录（app/routes.php 里用 Kode\Http\App 注册闭包路由）。
 */

return [
    'attributes' => [
        'enabled' => true,
        'controllers' => [
            'app'   => 'app/http/controllers',        // 面向公众的 API
            'admin' => 'app/admin/http/controllers',  // 管理后台 API
        ],
    ],
    'scan' => [
        'app/http/controllers',
        'app/admin/http/controllers',
    ],
];
