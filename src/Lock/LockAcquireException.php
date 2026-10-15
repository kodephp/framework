<?php

declare(strict_types=1);

namespace Kode\Framework\Lock;

use Kode\Exception\Enum\ErrorCode;
use Kode\Exception\KodeException;

/**
 * 获取锁失败（{@see LockManager::run()} 在被占用时抛出）。
 */
final class LockAcquireException extends KodeException
{
    public static function for(string $key): self
    {
        return new self(
            ErrorCode::Conflict,
            '获取锁失败（已被占用）：' . $key,
            context: ['key' => $key],
        );
    }
}
