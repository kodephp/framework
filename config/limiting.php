<?php

/*
 * 限流配置（kode/limiting）
 *
 * driver   ：存储后端 memory | apcu | redis | memcached | pdo
 *            - memory：单进程内存（演示/单机可用，多进程不共享）
 *            - apcu  ：单机多进程共享（需 ext-apcu）
 *            - redis ：分布式共享（推荐生产）
 * algorithm：令牌桶 token_bucket | 滑动窗口 sliding_window | 固定窗口 counter
 *            | 漏桶 leaky_bucket | 滑动窗口计数器 sliding_window_counter
 * capacity ：总额度
 * rate     ：令牌桶=每秒补充速率；滑动窗口/固定窗口=时间窗口秒数
 */

return [
    'enabled' => true,
    'driver' => env('RATE_LIMIT_DRIVER', 'memory'),
    'algorithm' => env('RATE_LIMIT_ALGO', 'token_bucket'),
    'capacity' => (int) env('RATE_LIMIT_CAPACITY', 10),
    'rate' => (float) env('RATE_LIMIT_RATE', 1.0),
    'redis' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD'),
        'database' => (int) env('REDIS_DB', 0),
    ],
];
