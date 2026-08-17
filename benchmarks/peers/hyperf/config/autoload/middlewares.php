<?php

declare(strict_types=1);

/**
 * hyperf 全局中间件（HTTP 服务）。
 *
 * 仅当 HYPERF_MW=on 时注册与 kode/webman 同构的跨切面中间件
 * （CORS + 安全头 + 链路 ID + 访问日志），作「框架开启中间件」的公平对标。
 * 默认（off）为零中间件，≈ 框架内核基线。
 */
$on = ($_SERVER['HYPERF_MW'] ?? getenv('HYPERF_MW') ?? 'off') === 'on';

return [
    'http' => $on ? [
        \App\Middleware\CorsMiddleware::class,
        \App\Middleware\SecurityHeadersMiddleware::class,
        \App\Middleware\RequestIdMiddleware::class,
        \App\Middleware\AccessLogMiddleware::class,
    ] : [],
];
