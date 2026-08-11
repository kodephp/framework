<?php

/*
 * 限流配置（kode/limiting）
 *
 * driver   ：存储后端 memory | apcu | redis | memcached | pdo
 *            - memory：单进程内存（演示/单机可用，多进程不共享）
 *            - apcu  ：单机多进程共享（需 ext-apcu）
 *            - redis ：分布式共享（推荐生产，支持集群至多一次）
 *            - memcached / pdo：其他分布式后端
 * algorithm：令牌桶 token_bucket | 滑动窗口 sliding_window | 固定窗口 counter
 *            | 漏桶 leaky_bucket | 滑动窗口计数器 sliding_window_counter
 * capacity ：全局默认额度（#[RateLimit] 未声明时生效）
 * rate     ：令牌桶=每秒补充速率；滑动窗口/固定窗口=时间窗口秒数
 *
 * 规则与存储解耦：在路由/控制器上用 #[RateLimit] 声明「限制什么」，此处统一决定
 * 「状态存哪」。把 driver 改为 redis 即让所有限流（含 #[RateLimit]）变为分布式。
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
        // 部署模式：standalone（默认）| sentinel（哨兵高可用）| cluster（分片）
        'mode' => env('REDIS_MODE', 'standalone'),
        // 哨兵模式：哨兵地址列表与 master 名称
        'sentinels' => env('REDIS_SENTINELS')
            ? explode(',', (string) env('REDIS_SENTINELS'))
            : ['127.0.0.1:26379'],
        'master_name' => env('REDIS_MASTER', 'mymaster'),
        // 集群模式：节点地址列表
        'cluster_nodes' => env('REDIS_CLUSTER_NODES')
            ? explode(',', (string) env('REDIS_CLUSTER_NODES'))
            : ['127.0.0.1:7000'],
        // 所有限流键前缀（便于在多业务共用 Redis 时隔离）
        'prefix' => env('REDIS_PREFIX', 'kode:limiting:'),
    ],
    // pdo 后端（可选）：需提供 dsn / 账号 / 表名
    'pdo' => [
        'dsn' => env('RATE_LIMIT_PDO_DSN', 'sqlite::memory:'),
        'username' => env('RATE_LIMIT_PDO_USER'),
        'password' => env('RATE_LIMIT_PDO_PASS'),
        'table' => env('RATE_LIMIT_PDO_TABLE', 'limiting'),
    ],
];
