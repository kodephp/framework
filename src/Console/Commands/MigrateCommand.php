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
 *   kode migrate              # 执行全部待运行迁移
 *   kode migrate --step=1     # 仅执行下一批（一步）
 *   kode migrate --pretend    # 预演：真跑一遍再整体回滚（PostgreSQL/SQLite 才有意义）
 */
#[AsCommand(
    name: 'migrate',
    description: '执行待运行的数据库迁移',
    usage: 'migrate {--step=} {--pretend}',
)]
final class MigrateCommand extends Command
{
    /** 本命令认识的选项 */
    private const OPTS = ['step', 'pretend'];

    protected function handle(): int
    {
        // 不静默忽略：写库命令上「以为传了 dry-run、实际全量执行」是最贵的误会
        if (($bad = $this->rejectUnknownOptions(self::OPTS)) !== null) {
            return $bad;
        }

        $step = null;
        if ($this->input->provided('step')) {
            // 没写 --step 才是「不限步数」；写了就必须是个 ≥1 的整数。
            // 放进 opt() 的默认值兜底会反过来：`(int) 'abc'` 是 0（一步都不跑）、
            // 光秃秃的 `--step` 是 null（不限步数），两者都跟用户写的意思相反，且照样退出 0。
            $given = $this->opt('step');
            if (!$this->input->validate('step', $given, ['numeric', 'min:1'])) {
                $this->error('--step 需要 ≥1 的整数，收到: ' . var_export($given, true));

                return 1;
            }
            $step = (int) $given;
        }
        $pretend = $this->flag('pretend');

        /** @var Migrator $migrator */
        $migrator = resolve(Migrator::class);

        $executed = $pretend ? $migrator->pretend($step) : $migrator->run($step);

        if ($executed === []) {
            $this->info($pretend ? '没有需要预演的迁移。' : '没有需要执行的迁移。');

            return 0;
        }

        foreach ($executed as $name) {
            $this->line('  ✓ ' . $name);
        }
        if ($pretend) {
            $this->success(sprintf('预演通过 %d 个迁移（已回滚，未落库）。', count($executed)));

            return 0;
        }
        $this->success(sprintf('已执行 %d 个迁移。', count($executed)));

        return 0;
    }
}
