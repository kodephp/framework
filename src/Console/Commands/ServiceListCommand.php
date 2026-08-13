<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\ServiceDiscovery\ServiceDiscovery;

/**
 * 列出已注册的上游服务及其实例（服务发现）。
 *
 *   bin/kode console service:list
 *
 * 展示每个服务的实例地址、健康状态与权重，便于排障与核对发现结果。
 */
#[AsCommand(
    name: 'service:list',
    description: '列出已注册的上游服务及其实例（服务发现）',
    usage: 'service:list',
)]
final class ServiceListCommand extends Command
{
    protected function handle(): int
    {
        if (!app()->container->bound(ServiceDiscovery::class)) {
            $this->warn('服务发现未启用（config/services.php 的 services.enabled = true 才会接线）。');

            return 0;
        }

        /** @var ServiceDiscovery $sd */
        $sd = resolve(ServiceDiscovery::class);

        $names = $sd->names();
        if ($names === []) {
            $this->info('当前没有任何已注册的服务。');

            return 0;
        }

        $this->info('已注册服务（' . count($names) . ' 个）：');

        $rows = [];
        foreach ($names as $name) {
            $instances = $sd->discover($name);
            if ($instances === []) {
                $rows[] = [$name, '（无实例）', '-', '-'];

                continue;
            }
            foreach ($instances as $instance) {
                $rows[] = [
                    $name,
                    $instance->url(),
                    $instance->healthy ? 'healthy' : 'UNHEALTHY',
                    (string) $instance->weight,
                ];
            }
        }

        $this->table(['service', 'endpoint', 'health', 'weight'], $rows);

        return 0;
    }
}
