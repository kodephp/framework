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
        ],

        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_SQLITE_PATH', storage_path('database.sqlite')),
        ],
    ],

    // 慢查询日志（写入 Monolog）
    'slow_log' => [
        'enabled' => true,
        'threshold' => 0.5,
    ],

    /*
     * 请求级数据库事务（原子化写请求）。
     * 开启后，框架对 POST/PUT/PATCH/DELETE 自动开事务，成功提交、异常回滚，
     * 保证「一次 HTTP 请求 = 一个原子工作单元」。默认关闭，按需开启。
     * transaction_skip_paths 中的路径（如探针）不开启事务。
     */
    'auto_transaction' => (bool) env('DB_AUTO_TRANSACTION', false),
    'transaction_skip_paths' => ['/health', '/metrics', '/ping'],
];
