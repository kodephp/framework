<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience\Backoff;

/**
 * 固定退避：每次重试等待相同秒数。
 *
 * 适合故障恢复快、且对抖动不敏感的场景（如本地文件系统重试）。
 */
final class FixedBackoff implements BackoffStrategy
{
    public function __construct(
        private readonly float $delay = 0.1,
    ) {
    }

    public function delay(int $attempt): float
    {
        return max(0.0, $this->delay);
    }
}
