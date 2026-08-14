<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience;

/**
 * 重试耗尽异常。
 *
 * 当 {@see Retry} 在达到最大尝试次数（或被 retryOn 判定不可重试 / 超出 timeout 预算）后仍失败时抛出。
 * 携带实际尝试次数与历次失败，便于排查「到底在哪些步、因什么错」最终放弃。
 */
final class RetryExhausted extends \RuntimeException
{
    /**
     * @param int                $attempts 实际尝试次数（>=1）
     * @param list<\Throwable>   $failures 历次失败（按发生顺序）
     */
    public function __construct(
        public readonly int $attempts,
        public readonly array $failures,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message ?: sprintf('操作在 %d 次尝试后仍失败', $attempts),
            $code,
            $previous,
        );
    }

    /**
     * 最后一次失败（用于异常链 / 日志）。
     */
    public function last(): ?\Throwable
    {
        if ($this->failures === []) {
            return null;
        }

        return $this->failures[count($this->failures) - 1];
    }
}
