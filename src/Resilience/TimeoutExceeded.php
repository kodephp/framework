<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience;

/**
 * 操作超时异常。
 *
 * 由 {@see Timeout} 原语在「受保护操作」超出 {@see Timeout::$defaultSeconds} 预算时抛出，
 * 携带超时秒数与标识，绝不静默吞掉——调用方必须显式处理（重试 / 降级 / 上报）。
 *
 * 与 kode/fibers 的 {@see \Kode\Fibers\Concurrency\TimeoutException} 解耦：
 * 框架只暴露自有契约类型，底层定时能力委托运行时（fiber / pcntl / sync），
 * 由对应调度器把运行时异常收敛为本类型。
 */
final class TimeoutExceeded extends \RuntimeException
{
    /**
     * @param float          $seconds 允许的超时秒数
     * @param string         $label   超时操作标识（日志 / 事件）
     * @param \Throwable|null $previous 触发超时的底层原因（如有）
     */
    public function __construct(
        public readonly float $seconds,
        public readonly string $label = 'anonymous',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Operation "%s" exceeded timeout of %.3fs', $label, $seconds),
            0,
            $previous,
        );
    }
}
