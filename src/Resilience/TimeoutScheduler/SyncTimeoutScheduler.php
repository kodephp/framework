<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience\TimeoutScheduler;

use Kode\Framework\Resilience\TimeoutExceeded;
use Kode\Framework\Resilience\TimeoutScheduler;

/**
 * 退化型同步超时后端（无 fiber、无 pcntl 时的保底）。
 *
 * PHP 单进程无法抢占正在执行的同步调用，故本后端在 $op 运行结束后比对实际耗时：
 * 若超出预算则抛 {@see TimeoutExceeded}——诚实暴露「预算越界」，但不阻断 $op 本身。
 *
 * 这是「故障可见性」而非「故障隔离」：用于既没有协程调度器、也不适合 pcntl 的环境，
 * 让超预算调用至少被记录与上报，而不是静默通过。如要真实抢占，请启用 fiber（默认）或 pcntl。
 */
final class SyncTimeoutScheduler implements TimeoutScheduler
{
    public function run(callable $op, float $seconds): mixed
    {
        $start = microtime(true);
        $result = $op();
        $elapsed = microtime(true) - $start;

        if ($elapsed > $seconds) {
            throw new TimeoutExceeded($seconds, 'anonymous');
        }

        return $result;
    }
}
