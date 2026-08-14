<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience\Backoff;

/**
 * 去相关抖动退避（AWS 风格 decorrelated jitter）。
 *
 * 与「每次都等于 base*2^(n-1)」的纯指数不同，本策略把上一次延迟作为随机上限，
 * 计算 next = base + random*(previous*multiplier - base)，并把 previous 更新为 next。
 * 好处：延迟不会无限逼近 cap 而被「钉死」，且相邻重试间隔天然错峰，抗惊群更强。
 *
 * 典型参数：base=0.1，multiplier=3.0，cap=10.0（AWS 官方推荐值区间）。
 */
final class DecorrelatedJitterBackoff implements BackoffStrategy
{
    private float $previous;

    public function __construct(
        private readonly float $base = 0.1,
        private readonly float $cap = 10.0,
        private readonly float $multiplier = 3.0,
    ) {
        $this->previous = $base;
    }

    public function delay(int $attempt): float
    {
        $min = $this->base;
        $span = max(0.0, $this->previous * $this->multiplier - $min);
        $r = mt_rand() / (float) mt_getrandmax();               // [0, 1]
        $next = $min + $span * $r;

        $this->previous = $next;

        return min($next, max(0.0, $this->cap));
    }
}
