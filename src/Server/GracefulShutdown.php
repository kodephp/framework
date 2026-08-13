<?php

declare(strict_types=1);

namespace Kode\Framework\Server;

/**
 * 优雅停机管理器（每个 worker 进程一个实例）。
 *
 * 职责边界（薄壳）：kode/process 已经在 SIGTERM 时负责「停收新连接 → 等在途连接自然关闭
 * → 触发 workerStop 事件」，本类只补上**业务层**两件事：
 *
 *  1. 在途请求计数：每请求入/出各 ±1，便于观测排空进度（metrics / 探针 / 日志）。
 *  2. 清理回调注册表：在 workerStop 时统一执行「落盘、flush 队列、关闭连接、
 *     注册中心下线」等收尾动作，且幂等（绝不让清理异常拖慢停机）。
 *
 * 为何要单独计数与清理：kode/process 的宽限计时器到点即结束事件循环，但业务侧常常还有
 * 「内存中攒批的队列任务」「未提交的指标」「持有的 DB/缓存连接」需要主动 flush/关闭，
 * 否则会丢任务或留下孤儿连接。这些收尾必须由框架在 workerStop 时机统一兜底。
 *
 * 多进程隔离：每个 worker 是独立进程，Application 在 fork 后各自重建，本实例也随之各一份，
 * 计数不存在跨进程串扰（与 SessionManager 单例需请求级隔离是同一类坑，这里天然规避）。
 */
final class GracefulShutdown
{
    /** 当前在途请求数（已进入 handle、尚未返回响应）。 */
    private int $inFlight = 0;

    /** 是否已进入停机流程。 */
    private bool $shuttingDown = false;

    /** 清理回调是否已执行（保证幂等）。 */
    private bool $cleanedUp = false;

    /** 进入停机流程的时间戳（microtime），用于日志/指标。 */
    private ?float $shutdownAt = null;

    /** 注册的业务清理回调（按注册顺序执行）。 */
    private array $cleanups = [];

    /**
     * 包住一次请求处理的生命周期：进出各计数一次；若在停机流程中且该请求是最后一个在途，
     * 立即触发清理（不必死等宽限计时器）。
     *
     * @template T
     * @param callable(): T $handler
     * @return T
     */
    public function track(callable $handler): mixed
    {
        ++$this->inFlight;

        try {
            return $handler();
        } finally {
            $this->inFlight = max(0, $this->inFlight - 1);

            if ($this->shuttingDown && $this->inFlight === 0) {
                $this->shutdown();
            }
        }
    }

    /**
     * 进入停机流程：置标志、记录时间；若无在途请求则立即执行清理。
     *
     * 通常由 {@see \Kode\Framework\Lifecycle\WorkerStopping} 监听器调用（kode/process 排空后触发）。
     */
    public function shutdown(): void
    {
        if ($this->cleanedUp) {
            return;
        }

        $this->shuttingDown = true;
        if ($this->shutdownAt === null) {
            $this->shutdownAt = microtime(true);
        }

        // 仍有在途请求时，交给 track() 的 finally 在最后一个完成时触发本方法；
        // 此处先尝试一次（无在途即立即清理，避免漏执行）。
        if ($this->inFlight === 0) {
            $this->runCleanup();
        }
    }

    /**
     * 注册一个停机清理回调（如 flush 队列、关闭连接、注册中心下线）。
     *
     * 回调体务必自行 try/catch 或保证不抛；即便抛了，{@see runCleanup()} 也会兜底吞掉，
     * 绝不让清理异常阻断整个 worker 退出。
     *
     * @param callable(): void $callback
     */
    public function registerCleanup(callable $callback): void
    {
        $this->cleanups[] = $callback;
    }

    /**
     * 按注册顺序执行全部清理回调（异常逐个吞掉），执行后置 cleanedUp 保证幂等。
     */
    public function runCleanup(): void
    {
        if ($this->cleanedUp) {
            return;
        }

        $this->cleanedUp = true;

        foreach ($this->cleanups as $callback) {
            try {
                $callback();
            } catch (\Throwable) {
                // 清理失败不应阻断停机；具体原因由回调自身记录日志。
            }
        }

        $this->cleanups = [];
    }

    public function inFlight(): int
    {
        return $this->inFlight;
    }

    public function isShuttingDown(): bool
    {
        return $this->shuttingDown;
    }

    public function isCleanedUp(): bool
    {
        return $this->cleanedUp;
    }

    /**
     * 观测快照（便于写入 metrics / 探针 / 日志）。
     *
     * @return array{inflight: int, shutting_down: bool, cleaned_up: bool, shutdown_at: ?float}
     */
    public function stats(): array
    {
        return [
            'inflight'      => $this->inFlight,
            'shutting_down' => $this->shuttingDown,
            'cleaned_up'    => $this->cleanedUp,
            'shutdown_at'   => $this->shutdownAt,
        ];
    }
}
