<?php

/*
 * HTTP 多进程服务配置（kode/process master-worker 运行时）
 *
 * 由 `bin/kode serve` 读取；CLI 参数（--host/--port/--workers）优先级更高。
 */

return [
    'host'        => env('SERVER_HOST', '127.0.0.1'),
    'port'        => (int) env('SERVER_PORT', 9527),
    'workers'     => (int) env('SERVER_WORKERS', 0), // 0 = 自动取 CPU 核心数
    'max_request' => (int) env('SERVER_MAX_REQUEST', 0),
    'reuse_port'  => (bool) env('SERVER_REUSE_PORT', false),
    'name'        => env('SERVER_NAME', 'kode-api-admin-demo'),

    'graceful_shutdown_timeout' => (int) env('SERVER_GRACE_PERIOD', 30),

    // 开发期热重载（serve --watch）
    'watch' => [
        'dirs'    => ['app', 'config'],
        'exclude' => ['vendor', '.git', 'storage', 'runtime', 'node_modules', '.workbuddy'],
    ],
];
