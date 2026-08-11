<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience;

/**
 * 进程内熔断器（纯 PHP 状态机，运行时无关）。
 *
 * 刻意不依赖 kode/fibers / 协程 / 事件循环：仅用 microtime() + 类属性，
 * 因此可在 process worker、fiber 任务、普通 HTTP handler、queue consumer
 * 等任意运行时通用。状态机语义：CLOSED → OPEN → HALF_OPEN → CLOSED。
 *
 * 这是「框架级」默认引擎。理想情况下该原语应下沉到 kode/core
 * （或独立的 kode/resilience 包），让各运行时共享同一实现；届时只需在
 * ResilienceServiceProvider 的工厂里替换构造目标即可，业务代码无感。
 */
final class InMemoryBreaker implements CircuitBreaker
{
    /** 状态：已闭合（正常放行） */
    public const string STATE_CLOSED = 'closed';
    /** 状态：已断开（快速失败） */
    public const string STATE_OPEN = 'open';
    /** 状态：半开（试探性放行） */
    public const string STATE_HALF_OPEN = 'half_open';

    private string $state = self::STATE_CLOSED;
    private int $failureCount = 0;
    private ?float $openedAt = null;
    private int $halfOpenCalls = 0;

    /**
     * @param int   $failureThreshold  连续失败达到该次数后熔断（进入 open）
     * @param float $recoveryTimeout   熔断后多久进入半开探活（秒）
     * @param int   $halfOpenMaxCalls  半开状态允许的试探请求数
     */
    public function __construct(
        private readonly int $failureThreshold = 5,
        private readonly float $recoveryTimeout = 30.0,
        private readonly int $halfOpenMaxCalls = 1,
    ) {
    }

    #[\Override]
    public function allowRequest(): bool
    {
        // 闭合状态：直接放行
        if ($this->state === self::STATE_CLOSED) {
            return true;
        }

        // 断开状态：检查恢复窗口是否到期
        if ($this->state === self::STATE_OPEN) {
            $openedAt = $this->openedAt ?? microtime(true);
            if ((microtime(true) - $openedAt) >= $this->recoveryTimeout) {
                $this->state = self::STATE_HALF_OPEN;
                $this->halfOpenCalls = 0;
            } else {
                return false;
            }
        }

        // 半开状态：限制试探请求数
        if ($this->state === self::STATE_HALF_OPEN) {
            if ($this->halfOpenCalls >= $this->halfOpenMaxCalls) {
                return false;
            }

            $this->halfOpenCalls++;
            return true;
        }

        return true;
    }

    #[\Override]
    public function recordSuccess(): void
    {
        $this->failureCount = 0;
        $this->halfOpenCalls = 0;
        $this->openedAt = null;
        $this->state = self::STATE_CLOSED;
    }

    #[\Override]
    public function recordFailure(): void
    {
        $this->failureCount++;

        // 半开状态失败或失败次数达到阈值时进入断开状态
        if ($this->state === self::STATE_HALF_OPEN || $this->failureCount >= $this->failureThreshold) {
            $this->state = self::STATE_OPEN;
            $this->openedAt = microtime(true);
            $this->halfOpenCalls = 0;
        }
    }

    #[\Override]
    public function state(): string
    {
        return $this->state;
    }

    #[\Override]
    public function metrics(): array
    {
        return [
            'state' => $this->state,
            'failure_count' => $this->failureCount,
            'opened_at' => $this->openedAt,
            'recovery_timeout' => $this->recoveryTimeout,
            'half_open_max_calls' => $this->halfOpenMaxCalls,
        ];
    }
}
