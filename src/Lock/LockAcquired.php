<?php

declare(strict_types=1);

namespace Kode\Framework\Lock;

/**
 * 锁获取成功事件。
 *
 * 由 {@see LockManager::acquire()} 在成功获取（含同 owner 重入刷新 TTL）后派发，
 * 便于接入指标（锁竞争 / 持有时长）/ 审计。
 *
 * @property-read string $key 锁键
 * @property-read string $owner 持有者令牌
 * @property-read int $ttl 存活秒数
 */
final class LockAcquired
{
    public function __construct(
        public readonly string $key,
        public readonly string $owner,
        public readonly int $ttl,
    ) {
    }
}
