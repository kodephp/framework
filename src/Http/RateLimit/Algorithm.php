<?php

declare(strict_types=1);

namespace Kode\Framework\Http\RateLimit;

use Kode\Limiting\Enum\LimiterType;

/**
 * 限流算法名 ↔ kode/limiting LimiterType 的映射（薄适配）。
 *
 * config/limiting.php 的 algorithm 用「人类可读字符串」配置，而 kode/limiting
 * 的 {@see LimiterType} 是枚举；本类统一做字符串 → 枚举的解析，避免 Provider 与
 * 中间件各写一份 match 导致分叉。
 */
final class Algorithm
{
    public static function fromName(string $name): LimiterType
    {
        return match ($name) {
            'sliding_window' => LimiterType::SLIDING_WINDOW,
            'sliding_window_counter' => LimiterType::SLIDING_WINDOW_COUNTER,
            'counter' => LimiterType::COUNTER,
            'leaky_bucket' => LimiterType::LEAKY_BUCKET,
            default => LimiterType::TOKEN_BUCKET,
        };
    }
}
