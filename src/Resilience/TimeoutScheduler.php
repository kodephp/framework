<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience;

/**
 * 超时调度契约。
 *
 * {@see Timeout} 原语只负责「编排 + 事件 + 降级」；真正的「计时 / 抢占」能力由本契约的实现提供。
 * 框架内置三种零依赖后端，分别适配不同运行时：
 *
 *  - {@see \Kode\Framework\Resilience\TimeoutScheduler\FiberTimeoutScheduler}：委托 kode/fibers 协程调度器，
 *    对「会挂起（I/O、sleep）」的协作式任务做真实抢占——框架 active runtime 的默认路径；
 *  - {@see \Kode\Framework\Resilience\TimeoutScheduler\PcntlTimeoutScheduler}：CLI 下用 pcntl_alarm 硬中断，
 *    对 CPU 密集型阻塞任务生效（opt-in，避免与事件循环信号冲突）；
 *  - {@see \Kode\Framework\Resilience\TimeoutScheduler\SyncTimeoutScheduler}：退化实现，运行后比对耗时，
 *    用于既无 fiber 也无 pcntl 的环境——诚实暴露预算越界，但不做抢占。
 *
 * 业务代码只依赖本契约，后端可替换（如接 Swoole Timer、接共享限流）。
 */
interface TimeoutScheduler
{
    /**
     * 在 $seconds 内运行 $op；超时抛 {@see TimeoutExceeded}（各后端自行把运行时异常收敛为该类型）。
     *
     * @param callable $op 受保护操作
     * @param float    $seconds 超时秒数
     * @return mixed $op 的返回值
     *
     * @throws TimeoutExceeded 超时
     * @throws \Throwable      $op 自身抛出的异常（原样透传，不包裹为超时）
     */
    public function run(callable $op, float $seconds): mixed;
}
