<?php

/*
 * 队列配置（kode/queue，PHP 8.3+，不可变消息、至少一次投递、内建 Worker）
 *
 * driver 支持 redis / database / beanstalkd / amqp / kafka（需对应扩展/连接）。
 * 留空时 QueueManager::make() 走 fromEnv()/auto() 自动探测。
 */

return [
    'default' => env('QUEUE_CONNECTION', 'redis'),

    'connections' => [
        'redis' => [
            'driver' => 'redis',
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD'),
            'database' => (int) env('REDIS_DB', 0),
            'queue' => env('QUEUE_NAME', 'default'),
        ],

        'database' => [
            'driver' => 'database',
            'table' => env('QUEUE_TABLE', 'jobs'),
        ],
    ],

    // 失败任务（死信）存储
    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database'),
        'table' => 'failed_jobs',
    ],
];
