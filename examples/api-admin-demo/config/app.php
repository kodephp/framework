<?php

/*
 * 应用基础配置（示例项目）
 */

return [
    'name' => env('APP_NAME', 'kode-api-admin-demo'),
    'debug' => (bool) env('APP_DEBUG', true),
    'timezone' => env('APP_TIMEZONE', 'Asia/Shanghai'),
    'env' => env('APP_ENV', 'local'),

    // 启动期必填配置（缺省即 fail-fast）
    'required' => [
        'app.name',
        'app.env',
    ],

    // 额外 ServiceProvider（框架内置已默认注册）
    'providers' => [],

    // 活动运行时：Web 框架默认 fiber（单进程协程，NTS/ZTS 均可）
    'runtime' => ['fiber'],
];
