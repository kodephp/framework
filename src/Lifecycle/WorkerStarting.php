<?php

declare(strict_types=1);

namespace Kode\Framework\Lifecycle;

/**
 * Worker 启动事件（每个 worker 进程开始接客前触发，应用已就绪）。
 *
 * 用于 worker 级初始化：建立独立连接池、启动周期任务、打印就绪日志等。
 *
 * $addTimer 是事件循环的周期定时器注册器（`(float 间隔秒, callable): int`），
 * 由 {@see \Kode\Framework\Server\HttpServer} 在 worker 事件循环内注入。
 * 只在「循环确实在跑」的时机才有值：Swoole 扩展在纯 CLI 下同样加载，据此判断
 * 会误注册（ shutdown 触发 Event::wait 永久挂起），故由服务端显式传入，
 * 拿不到（FPM / CLI / 未启动循环）即为 null，监听方应退化为不注册周期任务。
 */
final class WorkerStarting
{
    public function __construct(
        public readonly int $workerId,
        public readonly ?\Closure $addTimer = null,
    ) {
    }
}
