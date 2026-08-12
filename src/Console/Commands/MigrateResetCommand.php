<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Database\Database\Migrations\Migrator;
use Kode\Framework\Console\Command;

/**
 * 回滚全部迁移。
 *
 * 用法：
 *   bin/kode migrate:reset
 */
#[AsCommand(
    name: 'migrate:reset',
    description: '回滚全部迁移',
    usage: 'migrate:reset',
)]
final class MigrateResetCommand extends Command
{
    protected function handle(): int
    {
        /** @var Migrator $migrator */
        $migrator = resolve(Migrator::class);

        $rolled = $migrator->reset();

        if ($rolled === []) {
            $this->info('没有可回滚的迁移。');

            return 0;
        }

        foreach ($rolled as $name) {
            $this->line('  ✗ ' . $name);
        }
        $this->success(sprintf('已回滚全部 %d 个迁移。', count($rolled)));

        return 0;
    }
}
