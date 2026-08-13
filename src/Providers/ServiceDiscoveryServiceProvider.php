<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Event\Dispatcher;
use Kode\Framework\ServiceDiscovery\ServiceDiscovery;
use Kode\Framework\ServiceDiscovery\Contracts\ServiceRegistry;
use Kode\Framework\ServiceDiscovery\StaticServiceRegistry;

/**
 * 服务发现服务提供者（薄壳层）。
 *
 * 薄壳立场：
 *   - 框架不内置分布式发现客户端，只提供「可插拔注册表」抽象 + 运行时解析/负载均衡/健康检查 + 事件；
 *   - 内置 StaticServiceRegistry 把 config/services.php 声明加载为本地注册表，立即可用；
 *   - 真实发现后端（Consul/Nacos/ZooKeeper/Etcd）实现 ServiceRegistry 注入即可，框架零改动。
 *
 * 顺序要求：依赖 ConfigServiceProvider 已加载 config/services.php，故在 $defaults 中位于其后。
 */
final class ServiceDiscoveryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 实例与播种在 boot() 中完成（config 已加载），这里不提前绑定以免空壳。
    }

    public function boot(): void
    {
        if (!(bool) $this->config('services.enabled', true)) {
            return;
        }

        $registry = new StaticServiceRegistry();
        $registry->seed((array) $this->config('services.services', []));

        // 事件系统在启动顺序上可能晚于本 Provider，故用可选 callable 延迟派发，
        // 事件真正触发时（register/heartbeat）事件系统早已就绪。
        $dispatcher = $this->container->bound(Dispatcher::class)
            ? fn (object $event): object => $this->container->get(Dispatcher::class)->dispatch($event)
            : null;

        $discovery = new ServiceDiscovery(
            $registry,
            $dispatcher,
            (string) $this->config('services.default_strategy', 'round_robin'),
        );

        $this->container->instance(ServiceRegistry::class, $registry);
        $this->container->instance(ServiceDiscovery::class, $discovery);
        $this->container->alias('service.discovery', ServiceDiscovery::class);
    }
}
