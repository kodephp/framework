<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Database\Database\Migrations\Migrator;
use Kode\Framework\Console\Command;

/**
 * 回滚数据库迁移（默认回滚最近一个批次）。
 *
 * 用法：
 *   bin/kode migrate:rollback            # 回滚最近一批
 *   bin/kode migrate:rollback --step=2   # 回滚最近两个批次
 */
#[AsCommand(
    name: 'migrate:rollback',
    description: '回滚最近一批（或指定批次）迁移',
    usage: 'migrate:rollback [--step=N]',
)]
final class MigrateRollbackCommand extends Command
{
    protected function handle(): int
    {
        /** @var Migrator $migrator */
        $migrator = resolve(Migrator::class);

        $step = (int) $this->opt('step', 1);

        $rolled = $migrator->rollback($step);

        if ($rolled === []) {
            $this->info('没有可回滚的迁移。');

            return 0;
        }

        foreach ($rolled as $name) {
            $this->line('  ✗ ' . $name);
        }
        $this->success(sprintf('已回滚 %d 个迁移。', count($rolled)));

        return 0;
    }
}
