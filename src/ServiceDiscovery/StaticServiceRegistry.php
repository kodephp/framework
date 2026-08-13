<?php

declare(strict_types=1);

namespace Kode\Framework\ServiceDiscovery;

use Kode\Framework\ServiceDiscovery\Contracts\ServiceRegistry;

/**
 * 静态服务注册表（内置后端，对应 config/services.php）。
 *
 * 把 config 里的「服务 → 实例列表」声明加载进内存，作为立即可用的本地注册表；
 * 同时也是真实发现后端的范本——应用侧 watch 远程中心变更 → 调 register()/unregister()
 * 同步实例，框架其余逻辑（resolve/heartbeat/事件）无需任何改动。
 */
final class StaticServiceRegistry implements ServiceRegistry
{
    /** @var array<string, ServiceInstance> id => 实例 */
    private array $byId = [];

    /** @var array<string, array<int, string>> 服务名 => [实例 id] */
    private array $byName = [];

    /**
     * 从 config/services.php 的 services 数组批量播种。
     *
     * @param array<string, array<int, array<string, mixed>>> $defs
     */
    public function seed(array $defs): void
    {
        foreach ($defs as $name => $list) {
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $spec) {
                if (!is_array($spec)) {
                    continue;
                }
                $host = (string) ($spec['host'] ?? '');
                if ($host === '') {
                    continue; // 缺 host 跳过
                }

                $port    = (int) ($spec['port'] ?? 80);
                $scheme  = (string) ($spec['scheme'] ?? 'http');
                $weight  = (int) ($spec['weight'] ?? 1);
                $meta    = (array) ($spec['metadata'] ?? []);
                $id      = (string) ($spec['id'] ?? sprintf('%s://%s:%d', $scheme, $host, $port));

                $this->register(new ServiceInstance(
                    id: $id,
                    name: (string) $name,
                    host: $host,
                    port: $port,
                    scheme: $scheme,
                    metadata: $meta,
                    weight: $weight,
                ));
            }
        }
    }

    public function register(ServiceInstance $instance): bool
    {
        $isNew = !isset($this->byId[$instance->id]);
        $this->byId[$instance->id] = $instance;

        $ids = $this->byName[$instance->name] ?? [];
        if (!in_array($instance->id, $ids, true)) {
            $ids[] = $instance->id;
            $this->byName[$instance->name] = $ids;
        }

        return $isNew;
    }

    public function unregister(string $id): void
    {
        $instance = $this->byId[$id] ?? null;
        if ($instance === null) {
            return;
        }

        unset($this->byId[$id]);

        $ids = $this->byName[$instance->name] ?? [];
        $ids = array_values(array_diff($ids, [$id]));
        if ($ids === []) {
            unset($this->byName[$instance->name]);
        } else {
            $this->byName[$instance->name] = $ids;
        }
    }

    public function get(string $id): ?ServiceInstance
    {
        return $this->byId[$id] ?? null;
    }

    public function discover(string $name): array
    {
        $ids = $this->byName[$name] ?? [];

        return array_values(array_filter(
            array_map(fn (string $id): ?ServiceInstance => $this->byId[$id] ?? null, $ids),
            static fn (?ServiceInstance $i): bool => $i !== null,
        ));
    }

    public function all(): array
    {
        $out = [];
        foreach (array_keys($this->byName) as $name) {
            $out[$name] = $this->discover($name);
        }

        return $out;
    }

    public function names(): array
    {
        return array_keys($this->byName);
    }

    public function count(): int
    {
        return count($this->byId);
    }
}
