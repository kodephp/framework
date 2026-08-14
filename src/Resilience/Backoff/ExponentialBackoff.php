<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience\Backoff;

/**
 * 指数退避（可选对称抖动）。
 *
 * delay(attempt) = min(cap, base * factor ** (attempt - 1))，开启 jitter 后在
 * [delay*(1-ratio), delay*(1+ratio)] 区间内对称抖动，避免大量客户端「惊群」同时重试。
 *
 * 这是最常用、最稳妥的瞬态故障恢复策略（网络抖动、限流 429、短暂不可用）。
 */
final class ExponentialBackoff implements BackoffStrategy
{
    public function __construct(
        private readonly float $base = 0.1,
        private readonly float $factor = 2.0,
        private readonly float $cap = 10.0,
        private readonly bool $jitter = true,
        private readonly float $jitterRatio = 0.25,
    ) {
    }

    public function delay(int $attempt): float
    {
        $raw = $this->base * ($this->factor ** max(0, $attempt - 1));

        if ($this->jitter) {
            $r = mt_rand() / (float) mt_getrandmax();           // [0, 1]
            $raw = $raw * (1.0 + ($r * 2.0 - 1.0) * $this->jitterRatio);
        }

        return min(max($raw, 0.0), max(0.0, $this->cap));
    }
}
