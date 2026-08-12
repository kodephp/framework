<?php

/*
 * 审计日志配置（合规 / 安全审计）
 *
 * 审计中间件记录每次请求的关键事实：method / path / query / status / 时延 /
 * 客户端 IP / 链路 ID / 当前用户 ID（需经过 AuthMiddleware 鉴权）。
 * 默认写入 PSR 日志器（框架 Monolog，落 storage/logs），可按需接入 ELK / 审计库。
 *
 * 说明：审计是「合规记录」，与 access_log（观测）互补——access_log 关注流量，
 * 审计关注「谁在什么时间对什么资源做了什么、结果如何」。
 */

return [
    // 是否启用审计
    'enabled' => (bool) env('AUDIT_ENABLED', true),

    // 跳过的基础设施端点（避免噪声）
    'ignore_paths' => [
        '/health', '/health/live', '/health/ready', '/ping', '/metrics',
    ],

    // 是否捕获当前用户 ID（依赖 AuthMiddleware 写入 kode/context 的 auth_user_id）
    'capture_user' => (bool) env('AUDIT_CAPTURE_USER', true),

    // 审计日志级别（info / warning / debug）
    'log_level' => env('AUDIT_LOG_LEVEL', 'info'),
];
