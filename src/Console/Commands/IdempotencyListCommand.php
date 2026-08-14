<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Idempotency\IdempotencyManager;

/**
 * 列出当前记录的幂等键（运维排查 / 去重巡检）。
 *
 *   bin/kode console idempotency:list           表格展示：键 / 剩余 TTL（秒）
 *   bin/kode console idempotency:list --json     以 JSON 输出（便于被监控 / 编排系统解析）
 *
 * memory 后端仅反映当前进程；file 后端反映同主机多进程。退出码恒为 0。
 */
#[AsCommand(
    name: 'idempotency:list',
    description: '列出当前记录的幂等键（键 / 剩余 TTL）',
    usage: 'idempotency:list [--json]',
)]
final class IdempotencyListCommand extends Command
{
    protected function handle(): int
    {
        if (!app()->container->bound(IdempotencyManager::class)) {
            $this->warn('幂等子系统未接线（IdempotencyServiceProvider 缺失）。');

            return 0;
        }

        /** @var IdempotencyManager $idem */
        $idem = resolve(IdempotencyManager::class);
        $store = $idem->store();

        if ($this->opt('json')) {
            $rows = [];
            foreach ($store->keys() as $key) {
                $rows[] = ['key' => $key, 'ttl' => $store->ttl($key)];
            }
            $this->line(json_encode([
                'count' => count($rows),
                'keys'  => $rows,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return 0;
        }

        if ($store->keys() === []) {
            $this->info('当前无记录的幂等键。');

            return 0;
        }

        $rows = [];
        foreach ($store->keys() as $key) {
            $rows[] = [$key, (string) ($store->ttl($key) ?? 0)];
        }
        $this->table(['幂等键', '剩余 TTL(秒)'], $rows);

        return 0;
    }
}
