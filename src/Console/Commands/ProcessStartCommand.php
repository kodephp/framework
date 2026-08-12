<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Process\ProcessManager;

/**
 * 启动常驻进程（真正 fork 各 worker 实例，需 CLI + ext-pcntl + ext-posix）。
 *
 *   bin/kode console process:start
 */
#[AsCommand(
    name: 'process:start',
    description: '启动常驻进程（fork 各 worker 实例，需 CLI + ext-pcntl + ext-posix）',
    usage: 'process:start',
)]
final class ProcessStartCommand extends Command
{
    protected function handle(): int
    {
        /** @var ProcessManager $manager */
        $manager = resolve(ProcessManager::class);

        if (!$manager->supportsForking()) {
            $this->error('当前环境不支持常驻进程：需 CLI SAPI + ext-pcntl + ext-posix。');
            $this->line('可用 `bin/kode console process:check` 在不 fork 的情况下验证 worker 逻辑。');

            return 1;
        }

        $this->info('启动常驻进程，已注册 ' . $manager->count() . ' 个 worker（Ctrl+C 或 SIGTERM 优雅停止）...');
        try {
            $manager->start();
        } catch (\Throwable $e) {
            $this->error('启动失败：' . $e->getMessage());

            return 1;
        }

        return 0;
    }
}
