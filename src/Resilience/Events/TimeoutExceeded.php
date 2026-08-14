<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience\Events;

/**
 * 操作超时事件。
 *
 * 由 {@see \Kode\Framework\Resilience\Timeout} 在受保护操作超时时派发，
 * 供可观测性 / 告警订阅（如记录慢调用、触发降级、上报 APM）。
 *
 * 与 {@see \Kode\Framework\Resilience\TimeoutExceeded} 异常同名但分处不同命名空间：
 * 事件是「事实通知」，异常是「控制流信号」——订阅方按需选择捕获其一。
 */
final class TimeoutExceeded
{
    /**
     * @param string         $label  超时操作标识
     * @param float          $seconds 允许的超时秒数
     * @param \Throwable|null $cause  底层触发原因（如有）
     */
    public function __construct(
        public readonly string $label,
        public readonly float $seconds,
        public readonly ?\Throwable $cause = null,
    ) {
    }
}
