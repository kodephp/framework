<?php

/*
 * 日志配置（Monolog，遵循 PSR-3）
 */

return [
    'name' => env('APP_NAME', 'kode'),
    'path' => base_path('storage/logs/app.log'),
    'level' => env('LOG_LEVEL', 'debug'),
    'rotate' => true,

    // 访问日志（结构化请求日志）：method/uri/status/latency/request_id/client_ip。
    // 生产建议开启，便于接 ELK/Loki 做流量观测与慢请求定位。
    'access_log' => [
        'enabled' => (bool) env('ACCESS_LOG_ENABLED', true),
    ],
];
