<?php

/*
 * 数据库配置（kode/database）
 *
 * 示例项目默认使用 SQLite，开箱即跑、零外部依赖。
 * 想切换到 MySQL：在 .env 设置 DB_CONNECTION=mysql 并填写下方 mysql 连接信息即可。
 */

return [
    'default' => env('DB_CONNECTION', 'sqlite'),

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
        ],

        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_SQLITE_PATH', storage_path('database.sqlite')),
        ],
    ],

    'slow_log' => [
        'enabled' => true,
        'threshold' => 0.5,
    ],

    'auto_transaction' => (bool) env('DB_AUTO_TRANSACTION', false),
    'transaction_skip_paths' => ['/health', '/metrics', '/ping'],

    'leak_rollback' => (bool) env('DB_LEAK_ROLLBACK', true),
    'release_per_request' => (bool) env('DB_RELEASE_PER_REQUEST', false),
];
