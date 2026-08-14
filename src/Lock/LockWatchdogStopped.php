<?php

declare(strict_types=1);

namespace Kode\Framework\Lock;

/**
 * 看门狗停止事件（work 完成、锁已释放）。
 *
 * 由 {@see LockWatchdog::protect()} 在 finally 释放锁后派发，便于接入指标
 * （总续期次数 / 实际持有时长）。
 *
 * @property-read string $key 锁键
 * @property-read string $owner 持有者令牌
 * @property-read int $renews 本次 protect 周期内的续期次数
 */
final class LockWatchdogStopped
{
    public function __construct(
        public readonly string $key,
        public readonly string $owner,
        public readonly int $renews,
    ) {
    }
}
