<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Process\ProcessManager;

/**
 * 列出已注册的常驻 worker。
 *
 *   bin/kode console process:list
 */
#[AsCommand(
    name: 'process:list',
    description: '列出已注册的常驻 worker（名称 / 间隔 / 实例数）',
    usage: 'process:list',
)]
final class ProcessListCommand extends Command
{
    protected function handle(): int
    {
        /** @var ProcessManager $manager */
        $manager = resolve(ProcessManager::class);

        $workers = $manager->workers();
        if ($workers === []) {
            $this->warn('未注册任何 worker（在 config/process.php 的 workers 列表里添加）。');

            return 0;
        }

        $this->info('已注册 worker：' . $manager->count());
        $rows = [];
        foreach ($workers as $w) {
            $rows[] = [$w->name(), (string) $w->interval(), (string) $w->instances()];
        }
        $this->table(['Name', 'Interval(s)', 'Instances'], $rows);

        return 0;
    }
}
