<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience;

/**
 * 熔断器契约（框架中性，运行时无关）。
 *
 * 仅描述「故障隔离状态机」的行为，不绑定任何具体运行时引擎、
 * 不依赖 Fiber / 协程 / 事件循环。因此同一契约可被 process worker、
 * fiber 任务、普通 HTTP handler、queue consumer 等任意运行时共享。
 *
 * 默认引擎见 {@see InMemoryBreaker}（进程内纯 PHP 实现）；
 * 需要分布式熔断时替换工厂构造目标（如 Redis 引擎）即可，业务无感。
 */
interface CircuitBreaker
{
    /**
     * 是否允许本次请求通过（closed 直接放行，open 看恢复窗口，half_open 受限试探）。
     */
    public function allowRequest(): bool;

    /**
     * 记录一次成功：重置熔断计数，回到 closed。
     */
    public function recordSuccess(): void;

    /**
     * 记录一次失败：计数达到阈值或处于 half_open 时转入 open。
     */
    public function recordFailure(): void;

    /**
     * 当前状态：closed | open | half_open。
     */
    public function state(): string;

    /**
     * 运行指标（状态、失败数、打开时间、恢复窗口等）。
     *
     * @return array<string, mixed>
     */
    public function metrics(): array;
}
