<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Idempotency\IdempotencyManager;

/**
 * 删除指定幂等键（重试放行 / 运维清理）。
 *
 *   bin/kode console idempotency:forget order:abc123   删除该 key，使相同请求可重新处理
 *
 * 退出码：删除成功 / key 本就不存在均为 0。
 */
#[AsCommand(
    name: 'idempotency:forget',
    description: '删除指定幂等键（重试放行 / 运维清理）',
    usage: 'idempotency:forget <key>',
)]
final class IdempotencyForgetCommand extends Command
{
    protected function handle(): int
    {
        if (!app()->container->bound(IdempotencyManager::class)) {
            $this->warn('幂等子系统未接线（IdempotencyServiceProvider 缺失）。');

            return 0;
        }

        $key = (string) $this->arg(0);
        if ($key === '') {
            $this->error('请提供要删除的幂等键：idempotency:forget <key>');

            return 1;
        }

        /** @var IdempotencyManager $idem */
        $idem = resolve(IdempotencyManager::class);
        $idem->forget($key);
        $this->info('已删除幂等键：' . $key);

        return 0;
    }
}
