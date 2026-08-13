<?php

declare(strict_types=1);

namespace Kode\Framework\ServiceDiscovery\Contracts;

use Kode\Framework\ServiceDiscovery\ServiceInstance;

/**
 * 服务注册表契约（薄壳层抽象）。
 *
 * 框架只定义抽象，不绑定任何具体发现后端。内置 StaticServiceRegistry 把 config 声明当注册表；
 * 真实分布式发现（Consul/Nacos/ZooKeeper/Etcd）实现本接口并注入容器即可，框架零改动。
 */
interface ServiceRegistry
{
    /**
     * 注册一个实例。返回 true 表示「新增」（此前不存在），false 表示已存在（更新）。
     */
    public function register(ServiceInstance $instance): bool;

    /**
     * 注销指定实例（按 id）。
     */
    public function unregister(string $id): void;

    /**
     * 按 id 取单个实例（不存在返回 null）。
     */
    public function get(string $id): ?ServiceInstance;

    /**
     * 列出某服务的全部实例（含健康状态）。
     *
     * @return array<int, ServiceInstance>
     */
    public function discover(string $name): array;

    /**
     * 所有服务及其实例。
     *
     * @return array<string, array<int, ServiceInstance>>
     */
    public function all(): array;

    /**
     * 已注册的服务名列表。
     *
     * @return array<int, string>
     */
    public function names(): array;

    /**
     * 已注册实例总数。
     */
    public function count(): int;
}
