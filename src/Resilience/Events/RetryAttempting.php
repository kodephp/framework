<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience\Events;

/**
 * 即将进行第 N 次重试事件（在等待退避前派发）。
 *
 * 便于接入指标（重试次数 / 重试耗时）或日志审计。
 *
 * @property-read string $label  重试标识（来自 retry() 的 label 选项）
 * @property-read int    $attempt 第几次重试（>=1）
 * @property-read float  $delay   本次将等待的秒数
 */
final class RetryAttempting
{
    public function __construct(
        public readonly string $label,
        public readonly int $attempt,
        public readonly float $delay,
    ) {
    }
}
