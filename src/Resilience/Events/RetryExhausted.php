<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience\Events;

/**
 * 重试耗尽事件（最终失败、抛出 {@see \Kode\Framework\Resilience\RetryExhausted} 之前派发）。
 *
 * 便于接入告警（重试全失败 = 下游持续不可用）。
 *
 * @property-read string     $label    重试标识
 * @property-read int        $attempts 实际尝试次数
 * @property-read \Throwable $last     最后一次失败
 */
final class RetryExhausted
{
    public function __construct(
        public readonly string $label,
        public readonly int $attempts,
        public readonly \Throwable $last,
    ) {
    }
}
