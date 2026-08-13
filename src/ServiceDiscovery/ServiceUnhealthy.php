<?php

declare(strict_types=1);

namespace Kode\Framework\ServiceDiscovery;

/**
 * 服务健康事件：实例由健康转为不健康。
 *
 * ServiceDiscovery::heartbeat($id, false) 且此前为健康状态时派发。应用可监听它做：
 *   - 熔断该实例流量（摘除）；
 *   - 告警 / 记录日志；
 *   - 触发重新发现或缩容决策。
 */
final class ServiceUnhealthy
{
    /**
     * @param string          $name     服务名
     * @param ServiceInstance $instance 变为不健康的实例
     * @param bool            $previous 变化前状态（始终为 true，事件语义即「健康→不健康」）
     */
    public function __construct(
        public readonly string $name,
        public readonly ServiceInstance $instance,
        public readonly bool $previous,
    ) {
    }
}
