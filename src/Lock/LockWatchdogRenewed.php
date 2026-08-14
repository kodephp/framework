<?php

declare(strict_types=1);

namespace Kode\Framework\Lock;

/**
 * 看门狗续期成功事件。
 *
 * 由 {@see LockWatchdog} 续期循环在每次成功刷新 TTL 后派发，便于接入指标
 * （续期次数 / 续期时延）/ 告警（续期失败率）。
 *
 * @property-read string $key 锁键
 * @property-read string $owner 持有者令牌
 * @property-read ?int $remaining 续期后剩余 TTL（秒）
 * @property-read int $count 本次 protect 周期的续期序号（从 1 开始）
 */
final class LockWatchdogRenewed
{
    public function __construct(
        public readonly string $key,
        public readonly string $owner,
        public readonly ?int $remaining,
        public readonly int $count,
    ) {
    }
}
