<?php

declare(strict_types=1);

namespace Kode\Framework\Lock;

/**
 * 锁释放事件。
 *
 * 由 {@see LockManager::release()}（owner 匹配）或 {@see LockManager::forceRelease()}
 * （运维兜底 / 死锁清理）派发；{@see $forced} 区分两种来源。
 *
 * @property-read string $key 锁键
 * @property-read string $owner 原持有者令牌
 * @property-read bool $forced 是否强制释放（forceRelease）
 */
final class LockReleased
{
    public function __construct(
        public readonly string $key,
        public readonly string $owner,
        public readonly bool $forced = false,
    ) {
    }
}
