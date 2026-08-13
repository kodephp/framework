<?php

declare(strict_types=1);

namespace Kode\Framework\Config;

/**
 * 配置热重载完成事件。
 *
 * ConfigCenter::reload() 成功后派发，携带「本次发生变化的顶层键」与重载时间戳。
 * 应用可监听它做运行期再配置，例如：
 *   - 调整日志级别（不重启进程）；
 *   - 重建限流阈值 / Feature Flag 缓存；
 *   - 通知连接池重连新地址。
 */
final class ConfigReloaded
{
    /**
     * @param array<int, string> $changedKeys 本次 reload 相对重载前发生变化的顶层键
     * @param int                $reloadedAt  reload 完成时的 Unix 时间戳
     */
    public function __construct(
        public readonly array $changedKeys,
        public readonly int $reloadedAt,
    ) {
    }
}
