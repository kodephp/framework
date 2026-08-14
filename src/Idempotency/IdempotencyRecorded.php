<?php

declare(strict_types=1);

namespace Kode\Framework\Idempotency;

/**
 * 幂等键首次记录成功事件。
 *
 * 由 {@see IdempotencyManager::once()} / {@see IdempotencyManager::seen()} 在成功记录后派发，
 * 便于接入指标（去重命中率）/ 审计。
 *
 * @property-read string $key 幂等键
 * @property-read int $ttl 存活秒数
 */
final class IdempotencyRecorded
{
    public function __construct(
        public readonly string $key,
        public readonly int $ttl,
    ) {
    }
}
