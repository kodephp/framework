<?php

declare(strict_types=1);

namespace Kode\Framework\Lock;

/**
 * 看门狗启动事件（锁获取成功、续期循环已就绪）。
 *
 * 由 {@see LockWatchdog::protect()} 在成功获取锁后派发，便于接入指标
 * （看门狗持有时长）/ 审计。
 *
 * @property-read string $key 锁键
 * @property-read string $owner 持有者令牌
 * @property-read int $ttl 存活秒数
 * @property-read int $interval 续期间隔（秒）
 */
final class LockWatchdogStarted
{
    public function __construct(
        public readonly string $key,
        public readonly string $owner,
        public readonly int $ttl,
        public readonly int $interval,
    ) {
    }
}
