<?php

/*
 * 数据库配置（kode/database，轻量级适配器，兼容 Laravel/ThinkPHP/Hyperf ORM）
 *
 * kode/database 是「静态代理」用法：框架在启动期调用 Db::setConfig($config)，
 * 业务侧用 Db::table('users')->where(...)->get() 或 db()->table(...) 编写查询。
 * 这里只放配置，不建立真实连接（连接懒加载到首次查询）。
 */

return [
    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            // 连接池（生产级，H1）：kode/database 的 PoolManager 仅当存在 pool 段时建池。
            // Native 下退化为 ProcessPool（per-worker 复用），Swoole/Fiber 下为对应协程池。
            // 默认关闭（兼容单连接）；生产建议开启并按压测调优 max/min。
            'pool' => env('DB_POOL_ENABLED', false) ? [
                'max' => (int) env('DB_POOL_MAX', 10),
                'min' => (int) env('DB_POOL_MIN', 2),
                'max_wait_time' => (int) env('DB_POOL_MAX_WAIT', 30),
                'max_idle_time' => (int) env('DB_POOL_MAX_IDLE', 60),
            ] : null,
        ],

        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_SQLITE_PATH', storage_path('database.sqlite')),
        ],
    ],

    /*
     * 请求级数据库事务（原子化写请求）。
     * 开启后，框架对 POST/PUT/PATCH/DELETE 自动开事务，成功提交、异常回滚，
     * 保证「一次 HTTP 请求 = 一个原子工作单元」。默认关闭，按需开启。
     * transaction_skip_paths 中的路径（如探针）不开启事务。
     */
    'auto_transaction' => (bool) env('DB_AUTO_TRANSACTION', false),
    'transaction_skip_paths' => ['/health', '/metrics', '/ping'],

    /*
     * 连接生命周期收口（ConnectionCleanupMiddleware）。
     * kode/database 1.15.5+ 缓存连接池后，常驻进程需在请求结束后做连接级收口，避免：
     *  - 泄漏事务跨请求延续（手动 begin 后抛异常未回滚）；
     *  - 单测 / CLI 之间连接互相污染。
     * leak_rollback：检测到请求结束时仍有未提交事务则强制回滚（默认开，绝不跨请求）。
     * release_per_request：响应后调用 Db::disconnect() 释放缓存连接（默认关，保留连接池
     *   性能；单测 / CLI / 连接易失效场景可设为 true）。
     */
    'leak_rollback' => (bool) env('DB_LEAK_ROLLBACK', true),
    'release_per_request' => (bool) env('DB_RELEASE_PER_REQUEST', false),

    /*
     * 慢查询 / 失败查询日志（kode/framework >= 1.7.8 读此键，kode/database >= 1.20 供事件）。
     * enabled 后每条 SQL 都会派发 SqlEvent（执行器侧的观测切面随之开启，有少量开销），
     * 超过 threshold（秒）的记录 warning、执行失败的记录 error，走应用日志通道。
     * 日志只含 连接名/耗时/SQL 文本，不含绑定参数（PII 不落盘）。
     */
    'slow_log' => [
        'enabled' => (bool) env('DB_SLOW_LOG', false),
        'threshold' => (float) env('DB_SLOW_LOG_THRESHOLD', 1),
    ],
];
