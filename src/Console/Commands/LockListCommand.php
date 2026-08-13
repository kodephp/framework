<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Lock\LockManager;

/**
 * 列出当前持有的锁（运维排查 / 死锁巡检）。
 *
 *   bin/kode console lock:list           表格展示：键 / owner / 剩余 TTL（秒）
 *   bin/kode console lock:list --json     以 JSON 输出（便于被监控 / 编排系统解析）
 *
 * memory 后端仅反映当前进程持有的锁；file 后端反映同主机多进程持有的锁。
 * 退出码恒为 0（仅展示，不影响部署）。
 */
#[AsCommand(
    name: 'lock:list',
    description: '列出当前持有的锁（键 / owner / 剩余 TTL）',
    usage: 'lock:list [--json]',
)]
final class LockListCommand extends Command
{
    protected function handle(): int
    {
        if (!app()->container->bound(LockManager::class)) {
            $this->warn('锁子系统未接线（LockServiceProvider 缺失）。');

            return 0;
        }

        /** @var LockManager $lock */
        $lock = resolve(LockManager::class);
        $keys = $lock->keys();

        if ($this->opt('json')) {
            $rows = [];
            foreach ($keys as $key) {
                $rows[] = [
                    'key'   => $key,
                    'owner' => $lock->owner($key),
                    'ttl'   => $lock->ttl($key),
                ];
            }
            $this->line(json_encode([
                'count' => count($rows),
                'locks' => $rows,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return 0;
        }

        if ($keys === []) {
            $this->info('当前无持有的锁。');

            return 0;
        }

        $rows = [];
        foreach ($keys as $key) {
            $rows[] = [$key, (string) $lock->owner($key), (string) ($lock->ttl($key) ?? 0)];
        }
        $this->table(['锁键', 'owner', '剩余 TTL(秒)'], $rows);

        return 0;
    }
}
