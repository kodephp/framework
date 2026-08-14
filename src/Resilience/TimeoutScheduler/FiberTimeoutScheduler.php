<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience\TimeoutScheduler;

use Kode\Fibers\Concurrency\TimeoutException as FiberTimeoutException;
use Kode\Fibers\Fibers;
use Kode\Framework\Resilience\Timeout;
use Kode\Framework\Resilience\TimeoutExceeded;
use Kode\Framework\Resilience\TimeoutScheduler;

/**
 * 基于 kode/fibers 协程调度器的超时后端（框架 active runtime 的默认路径）。
 *
 * 委托 {@see Fibers::withTimeout}：在事件循环内对「会挂起」的任务做真实抢占，
 * 在循环外（如普通 Web 请求）则临时拉起一个 Scheduler 驱动协程直至 join——
 * 两种情形都对协作式挂起（I/O、Fibers::sleep）生效。
 *
 * 把 kode/fibers 的 {@see FiberTimeoutException} 收敛为框架自有的 {@see TimeoutExceeded}，
 * 使调用方有稳定、与运行时无关的可捕获类型。
 */
final class FiberTimeoutScheduler implements TimeoutScheduler
{
    public function run(callable $op, float $seconds): mixed
    {
        try {
            return Fibers::withTimeout($op, $seconds);
        } catch (FiberTimeoutException $e) {
            throw new TimeoutExceeded($seconds, 'anonymous', $e);
        }
    }
}
