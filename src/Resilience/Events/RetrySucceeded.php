<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience\Events;

/**
 * 重试后成功事件（在第 2 次及以后尝试成功时派发；首次成功不派发）。
 *
 * 便于接入指标（重试恢复率）或日志审计。
 *
 * @property-read string $label    重试标识
 * @property-read int    $attempts 实际尝试次数（>=2）
 */
final class RetrySucceeded
{
    public function __construct(
        public readonly string $label,
        public readonly int $attempts,
    ) {
    }
}
