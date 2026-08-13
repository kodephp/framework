<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Tenant\Storage\TenantStorageManager;

/**
 * 租户存储隔离诊断命令（薄壳层运维抓手）。
 *
 *   bin/kode console tenant:storage:list                列出当前 storage 策略与显式映射
 *   bin/kode console tenant:storage:list --tenant=acme   解析并打印某租户将使用的连接名
 *
 * 仅展示「会注册到 kode/database 的连接名」，不真正连库、不切换默认连接（dry-run）。
 */
#[AsCommand(
    name: 'tenant:storage:list',
    description: '列出多租户存储隔离策略与租户连接映射',
    usage: 'tenant:storage:list [--tenant=NAME] [--json]',
)]
final class TenantStorageCommand extends Command
{
    protected function handle(): int
    {
        /** @var array<string, mixed> $tenant */
        $tenant = (array) config('tenant', []);
        /** @var array<string, mixed> $storage */
        $storage = (array) ($tenant['storage'] ?? []);

        if (empty($storage['enabled'])) {
            $this->line('<comment>租户存储隔离未启用（tenant.storage.enabled = false）。</comment>');

            return 0;
        }

        $specific = $this->opt('tenant');
        $rows = [];

        if ($specific !== null && $specific !== '') {
            /** @var TenantStorageManager|null $mgr */
            $mgr = app()->container->bound(TenantStorageManager::class)
                ? resolve(TenantStorageManager::class)
                : null;

            $name = $mgr?->connectionName((string) $specific);
            $rows[] = [(string) $specific, $name ?? '(默认连接 / 不隔离)'];
        } elseif (($storage['strategy'] ?? 'shared') === 'map') {
            /** @var TenantStorageManager $mgr */
            $mgr = resolve(TenantStorageManager::class);
            foreach (array_keys((array) ($storage['map'] ?? [])) as $tid) {
                $rows[] = [(string) $tid, $mgr->connectionName((string) $tid) ?? '(默认)'];
            }
        } else {
            $rows[] = ['(动态派生)', ($storage['prefix'] ?? 'tnt_') . '{tenantId}'];
        }

        $summary = [
            'enabled' => true,
            'strategy' => $storage['strategy'] ?? 'shared',
            'template' => $storage['template'] ?? 'mysql',
            'prefix' => $storage['prefix'] ?? 'tnt_',
            'on_missing' => $storage['on_missing'] ?? 'fallback',
            'resolver' => class_exists((string) ($storage['strategy'] ?? ''))
                ? $storage['strategy']
                : 'StaticTenantStorageResolver',
            'mappings' => $rows,
        ];

        if ($this->opt('json')) {
            $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return 0;
        }

        $this->line('<info>租户存储隔离</info>');
        $this->line('  策略     : ' . ($summary['strategy'] ?? '-'));
        $this->line('  模板连接 : ' . ($summary['template'] ?? '-'));
        $this->line('  库名前缀 : ' . ($summary['prefix'] ?? '-'));
        $this->line('  缺失行为 : ' . ($summary['on_missing'] ?? '-'));
        $this->line('  解析器   : ' . ($summary['resolver'] ?? '-'));
        $this->line('');
        $this->table(['租户', '连接名'], $rows);

        return 0;
    }
}
