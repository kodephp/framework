<?php

declare(strict_types=1);

namespace Kode\Framework\ServiceDiscovery;

use Closure;
use Kode\Framework\ServiceDiscovery\Contracts\ServiceRegistry;

/**
 * 服务发现管理器（薄壳层运行时核心）。
 *
 * 职责：
 *   1) 包裹一个 ServiceRegistry（内置静态 / 可插拔远程）；
 *   2) resolve()：从健康实例中按策略挑一个（round_robin / random / first）做客户端负载均衡；
 *   3) heartbeat()：上报实例健康，状态由健康→不健康时派发 ServiceUnhealthy 事件；
 *   4) register()：新增实例时派发 ServiceDiscovered 事件；
 *   5) stats()：暴露每服务的健康/不健康计数，供诊断与探针。
 *
 * 注意：
 *   - 多进程（kode/process master-worker）下每个 worker 各自持有注册表；
 *     跨进程同步的发现/健康检查由真实后端或运维编排负责，框架不越界。
 *   - 事件分发用构造注入的可选 callable 解耦，避免与事件系统启动顺序耦合。
 */
final class ServiceDiscovery
{
    /** @var array<string, int> 各服务的轮询游标 */
    private array $cursors = [];

    /**
     * @param ServiceRegistry      $registry        注册表后端
     * @param Closure|null         $dispatcher      事件派发器（注入，避免与事件系统启动顺序耦合）
     * @param string              $defaultStrategy 默认负载均衡策略
     */
    public function __construct(
        private readonly ServiceRegistry $registry,
        private readonly ?Closure $dispatcher = null,
        private readonly string $defaultStrategy = 'round_robin',
    ) {
    }

    /**
     * 注册实例；新增时派发 ServiceDiscovered 事件。
     */
    public function register(ServiceInstance $instance): void
    {
        $isNew = $this->registry->register($instance);
        if ($isNew && $this->dispatcher !== null) {
            ($this->dispatcher)(new ServiceDiscovered($instance->name, $instance));
        }
    }

    /**
     * 注销实例（按 id）。
     */
    public function unregister(string $id): void
    {
        $this->registry->unregister($id);
    }

    /**
     * 列出某服务的全部实例（含健康状态）。
     *
     * @return array<int, ServiceInstance>
     */
    public function discover(string $name): array
    {
        return $this->registry->discover($name);
    }

    /**
     * 列出某服务的健康实例。
     *
     * @return array<int, ServiceInstance>
     */
    public function healthy(string $name): array
    {
        return array_values(array_filter(
            $this->registry->discover($name),
            static fn (ServiceInstance $i): bool => $i->healthy,
        ));
    }

    /**
     * 解析一个健康实例（客户端负载均衡）。无健康实例返回 null。
     */
    public function resolve(string $name, ?string $strategy = null): ?ServiceInstance
    {
        $healthy = $this->healthy($name);
        if ($healthy === []) {
            return null;
        }

        $strategy = $strategy ?? $this->defaultStrategy;

        return match ($strategy) {
            'random' => $healthy[array_rand($healthy)],
            'first'  => $healthy[0],
            default  => $this->roundRobin($name, $healthy),
        };
    }

    /**
     * 解析某服务的完整 URL；无健康实例返回 null。
     */
    public function url(string $name, ?string $strategy = null): ?string
    {
        $instance = $this->resolve($name, $strategy);

        return $instance?->url();
    }

    /**
     * 上报实例健康状态。状态由健康→不健康时派发 ServiceUnhealthy 事件。
     *
     * @param string $id       实例 id（ServiceInstance::$id）
     * @param bool   $healthy  是否健康
     */
    public function heartbeat(string $id, bool $healthy = true): void
    {
        $instance = $this->registry->get($id);
        if ($instance === null) {
            return;
        }

        $was = $instance->healthy;
        $instance->healthy = $healthy;
        $instance->lastHealthAt = time();

        if ($was && !$healthy && $this->dispatcher !== null) {
            ($this->dispatcher)(new ServiceUnhealthy($instance->name, $instance, $was));
        }
    }

    /**
     * 已注册的服务名列表。
     *
     * @return array<int, string>
     */
    public function names(): array
    {
        return $this->registry->names();
    }

    /**
     * 所有服务及其实例。
     *
     * @return array<string, array<int, ServiceInstance>>
     */
    public function all(): array
    {
        return $this->registry->all();
    }

    /**
     * 每服务的健康统计快照。
     *
     * @return array<string, array{total: int, healthy: int, unhealthy: int}>
     */
    public function stats(): array
    {
        $out = [];
        foreach ($this->registry->names() as $name) {
            $instances = $this->registry->discover($name);
            $healthy = 0;
            foreach ($instances as $instance) {
                if ($instance->healthy) {
                    $healthy++;
                }
            }
            $out[$name] = [
                'total'     => count($instances),
                'healthy'   => $healthy,
                'unhealthy' => count($instances) - $healthy,
            ];
        }

        return $out;
    }

    /**
     * 轮询负载均衡：跨调用按游标均匀分摊到健康实例。
     *
     * @param array<int, ServiceInstance> $healthy
     */
    private function roundRobin(string $name, array $healthy): ServiceInstance
    {
        $idx = $this->cursors[$name] ?? 0;
        $picked = $healthy[$idx % count($healthy)];
        $this->cursors[$name] = ($idx + 1) % count($healthy);

        return $picked;
    }
}
