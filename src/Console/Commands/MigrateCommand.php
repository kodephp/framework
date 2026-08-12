<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Database\Database\Migrations\Migrator;
use Kode\Framework\Console\Command;

/**
 * 执行数据库迁移（运行 database/migrations 下待执行的迁移文件）。
 *
 * 用法：
 *   bin/kode migrate              # 执行全部待运行迁移
 *   bin/kode migrate --step=1     # 仅执行下一批（一步）
 */
#[AsCommand(
    name: 'migrate',
    description: '执行待运行的数据库迁移',
    usage: 'migrate [--step=N]',
)]
final class MigrateCommand extends Command
{
    protected function handle(): int
    {
        /** @var Migrator $migrator */
        $migrator = resolve(Migrator::class);

        $step = $this->opt('step');
        $step = $step !== null ? (int) $step : null;

        $executed = $migrator->run($step);

        if ($executed === []) {
            $this->info('没有需要执行的迁移。');

            return 0;
        }

        foreach ($executed as $name) {
            $this->line('  ✓ ' . $name);
        }
        $this->success(sprintf('已执行 %d 个迁移。', count($executed)));

        return 0;
    }
}
