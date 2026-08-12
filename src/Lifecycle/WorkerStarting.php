<?php

declare(strict_types=1);

namespace Kode\Framework\Lifecycle;

/**
 * Worker 启动事件（每个 worker 进程开始接客前触发，应用已就绪）。
 *
 * 用于 worker 级初始化：建立独立连接池、启动周期任务、打印就绪日志等。
 */
final class WorkerStarting
{
    public function __construct(
        public readonly int $workerId,
    ) {
    }
}
