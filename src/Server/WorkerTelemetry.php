<?php

declare(strict_types=1);

namespace Kode\Framework\Server;

/**
 * 单个 worker 进程的运行计数器（`bin/kode status` 的逐进程数据来源）。
 *
 * 为什么自己数，而不是读 kode/process 的 `stats()`：
 *  - `RuntimeInterface::stats()` 在本框架支持的最低 kode/process（5.2.x）上会触碰
 *    `Kode\Process\Version`，该类含 `public const int IS_ZTS = \PHP_ZTS;`——
 *    `PHP_ZTS` 是 bool，PHP 8.5 起「bool 赋给 int 类型常量」是致命错误，
 *    一旦加载即进程崩溃且**不可捕获**。故本框架一律不调用 `stats()`。
 *  - 计数器本就只需要在本进程内递增：连接数由 connect/close 事件驱动，
 *    请求数在消息处理入口 +1，二者都不跨进程，自数比向运行时索取更直接、更可控。
 *
 * 实例在 worker 进程内被多个闭包共享（消息处理 / 连接事件 / 心跳定时器），
 * 用对象承载计数比散落若干 `&$counter` 引用更易读，也不需要加锁（单进程）。
 */
final class WorkerTelemetry
{
    /** 当前存活连接数（keep-alive 长连接按一条计）。 */
    private int $connections = 0;

    /** 本 worker 累计处理的请求数（含 404 / 异常响应）。 */
    private int $requests = 0;

    /** 是否已收到停机信号（SIGINT / SIGTERM / SIGUSR1）。 */
    private bool $stopping = false;

    public function onConnect(): void
    {
        ++$this->connections;
    }

    public function onClose(): void
    {
        $this->connections = max(0, $this->connections - 1);
    }

    public function onRequest(): void
    {
        ++$this->requests;
    }

    public function markStopping(): void
    {
        $this->stopping = true;
    }

    public function connections(): int
    {
        return $this->connections;
    }

    public function requests(): int
    {
        return $this->requests;
    }

    public function isStopping(): bool
    {
        return $this->stopping;
    }
}
