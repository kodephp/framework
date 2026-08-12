<?php

declare(strict_types=1);

/*
 * 常驻进程配置
 *
 * 在此声明要常驻运行的 worker 列表；框架启动时会自动实例化并注册到
 * Kode\Framework\Process\ProcessManager（单例，门面 Process / 助手 process()）。
 *
 * workers 支持两种写法：
 *   1) 无参 worker：直接写类名
 *      'workers' => [ App\Process\HeartbeatWorker::class ]
 *   2) 带构造参数的 worker：写 class + config
 *      'workers' => [ ['class' => App\Process\CleanupWorker::class, 'config' => ['ttl' => 3600]] ]
 *
 * 每个 worker 自行决定：
 *   - name()    唯一名称
 *   - handle()  单次工作量（按 interval() 周期执行）
 *   - interval() 轮询间隔（秒）
 *   - instances() 并行实例数（fork 子进程数）
 *
 * 启动：bin/kode console process:start
 * 验证（不 fork）：bin/kode console process:check
 */
return [
    // 示例：启用一个演示 worker（心跳写入 storage/heartbeat.log）。
    // 验证：php bin/console process:check
    // 常驻：php bin/console process:start
    'workers' => [
        App\Process\HeartbeatWorker::class,
    ],
];
