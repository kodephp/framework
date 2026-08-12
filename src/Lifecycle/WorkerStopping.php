<?php

declare(strict_types=1);

namespace Kode\Framework\Lifecycle;

/**
 * Worker 停止事件（收到 SIGTERM/SIGINT，k8s 优雅停机前触发）。
 *
 * 用于优雅收尾：刷新指标、关闭连接、落盘、通知注册中心下线等。
 * 此时应快速完成（k8s 默认 terminationGracePeriodSeconds 内）。
 */
final class WorkerStopping
{
    public function __construct(
        public readonly int $workerId,
    ) {
    }
}
