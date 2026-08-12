<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Process\ProcessManager;

/**
 * 不 fork，同步跑一遍所有 worker 的 handle() 验证逻辑（CI / 无 pcntl 环境友好）。
 *
 *   bin/kode console process:check
 */
#[AsCommand(
    name: 'process:check',
    description: '不 fork，同步跑一遍所有 worker 的 handle() 验证逻辑',
    usage: 'process:check',
)]
final class ProcessCheckCommand extends Command
{
    protected function handle(): int
    {
        /** @var ProcessManager $manager */
        $manager = resolve(ProcessManager::class);

        if ($manager->count() === 0) {
            $this->warn('未注册任何 worker，check 无可执行内容。');

            return 0;
        }

        $ran = $manager->dryRun();
        $this->success('dryRun 通过，已同步执行 ' . count($ran) . ' 个 worker 的 handle()：');
        foreach ($ran as $name) {
            $this->line('  ✓ ' . $name);
        }

        return 0;
    }
}
