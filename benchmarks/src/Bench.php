<?php

declare(strict_types=1);

namespace Kode\Bench;

/**
 * 微型压测计时工具（零依赖）。
 *
 * 用 hrtime() 纳秒时钟采集每次请求耗时，计算吞吐量（ops/s）与
 * p50/p95/p99 延迟百分位（毫秒）。启动成本（框架 boot）在测量之外，
 * 预热（warmup）用于消除 PHP JIT 冷启动与 OPcache 未命中带来的噪声。
 */
final class Bench
{
    /**
     * 单次测量。
     *
     * @param callable(): void $fn      执行一次「请求」的闭包（已排除 boot 成本）
     * @param int              $warmup  预热次数（不计入统计）
     * @param int              $iters   正式采样次数
     *
     * @return array{
     *     ops:float, total:float, iters:int,
     *     p50:float, p95:float, p99:float, min:float, max:float, unit:string
     * }
     */
    public static function measure(callable $fn, int $warmup, int $iters): array
    {
        for ($i = 0; $i < $warmup; $i++) {
            $fn();
        }

        $samplesMs = [];
        $totalUs = 0.0;

        for ($i = 0; $i < $iters; $i++) {
            $t0 = hrtime(true);
            $fn();
            $t1 = hrtime(true);
            $us = ($t1 - $t0) / 1e3;          // 纳秒 → 微秒
            $samplesMs[] = $us / 1000.0;       // 微秒 → 毫秒
            $totalUs += $us;
        }

        sort($samplesMs);
        $n = count($samplesMs);

        return [
            'ops'    => $totalUs > 0 ? ($iters / ($totalUs / 1e6)) : 0.0,
            'total'  => $totalUs / 1e6,
            'iters'  => $iters,
            'p50'    => self::percentile($samplesMs, 50),
            'p95'    => self::percentile($samplesMs, 95),
            'p99'    => self::percentile($samplesMs, 99),
            'min'    => $samplesMs[0] ?? 0.0,
            'max'    => $samplesMs[$n - 1] ?? 0.0,
            'unit'   => 'ms',
        ];
    }

    /**
     * 线性插值百分位（输入必须为已排序数组）。
     */
    private static function percentile(array $sorted, int $q): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }

        $idx = ($q / 100) * ($n - 1);
        $lo = (int) floor($idx);
        $hi = (int) ceil($idx);

        if ($lo === $hi) {
            return (float) $sorted[$lo];
        }

        $frac = $idx - $lo;

        return (float) ($sorted[$lo] * (1 - $frac) + $sorted[$hi] * $frac);
    }
}
