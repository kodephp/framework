<?php

declare(strict_types=1);

namespace Kode\Framework\Idempotency;

use Kode\Exception\KodeException;

/**
 * 幂等键重复（{@see IdempotencyManager::once()} 在 TTL 内命中已处理 key 时抛出）。
 *
 * 上层（如 HTTP 幂等中间件）捕获后通常返回 409 Conflict 或缓存的首次响应。
 */
final class DuplicateRequest extends KodeException
{
    public static function for(string $key): self
    {
        return new self('幂等键重复（TTL 内已处理）：' . $key, 409);
    }
}
