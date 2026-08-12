<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience;

use Kode\Fibers\Core\CircuitBreaker as FiberCircuitBreaker;

/**
 * 熔断器薄适配。
 *
 * 状态机 / 恢复窗口 / 半开探活等算法完全委托 kode/fibers 的 CircuitBreaker
 * （与协程、多运行时通用）。本类只做接口对齐：框架 Breaker 注册表依赖中性
 * {@see CircuitBreaker} 契约，本类把调用转给包实现，不对算法做任何重复实现。
 */
final class FiberBreaker implements CircuitBreaker
{
    public function __construct(private readonly FiberCircuitBreaker $inner)
    {
    }

    public function allowRequest(): bool
    {
        return $this->inner->allowRequest();
    }

    public function recordSuccess(): void
    {
        $this->inner->recordSuccess();
    }

    public function recordFailure(): void
    {
        $this->inner->recordFailure();
    }

    public function state(): string
    {
        return $this->inner->state();
    }

    public function metrics(): array
    {
        return $this->inner->metrics();
    }
}
