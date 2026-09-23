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
 *   kode migrate:rollback            # 回滚最近一批
 *   kode migrate:rollback --step=2   # 回滚最近两个批次
 */
#[AsCommand(
    name: 'migrate:rollback',
    description: '回滚最近一批（或指定批次）迁移',
    usage: 'migrate:rollback {--step= : 回滚最近几个批次，默认 1}',
)]
final class MigrateRollbackCommand extends Command
{
    /** 本命令认识的选项 */
    private const OPTS = ['step'];

    protected function handle(): int
    {
        // 回滚是这批命令里最贵的写操作：`migrate:rollback --pretend` 被静默忽略
        // 就是「以为在看回放、实际把表撤了」
        if (($bad = $this->rejectUnknownOptions(self::OPTS)) !== null) {
            return $bad;
        }

        // 默认回滚最近一批；显式写了 --step 就必须是个 ≥1 的整数
        if (($bad = $this->checkIntOptions(['step'], 1)) !== null) {
            return $bad;
        }
        $step = (int) $this->opt('step', 1);

        /** @var Migrator $migrator */
        $migrator = resolve(Migrator::class);

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
