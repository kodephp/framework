<?php

declare(strict_types=1);

namespace Kode\Framework\ServiceDiscovery;

/**
 * 服务发现事件：新实例被发现 / 注册。
 *
 * ServiceDiscovery::register() 新增实例时派发。应用可监听它做运行期反应，例如：
 *   - 预热连接池 / 建立长连接；
 *   - 更新本地路由表 / 推送至网关；
 *   - 上报监控指标。
 */
final class ServiceDiscovered
{
    public function __construct(
        public readonly string $name,
        public readonly ServiceInstance $instance,
    ) {
    }
}
