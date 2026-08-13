<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Scheduling\ScheduleDispatcher;

/**
 * 常驻调度守护进程。
 *
 *   bin/kode console schedule:work                 # 持续轮询（默认 60s）
 *   bin/kode console schedule:work --interval=30   # 调整为 30s
 *
 * 持续调度，直到收到 SIGINT / SIGTERM 优雅退出（keepAlive 由 kode/scheduling 驱动）。
 * 与 schedule:run（单轮、由 crontab 触发）二选一：常驻更省进程、秒级任务更及时。
 */
#[AsCommand(
    name: 'schedule:work',
    description: '常驻调度守护（持续轮询，SIGTERM 优雅退出）',
    usage: 'schedule:work [--interval=60]',
)]
final class ScheduleWorkCommand extends Command
{
    protected function handle(): int
    {
        /** @var ScheduleDispatcher $dispatcher */
        $dispatcher = resolve(ScheduleDispatcher::class);

        $interval = (int) ($this->opt('interval') ?? 60);

        $this->info(sprintf('调度守护启动（间隔 %ds；Ctrl+C / SIGTERM 优雅退出）', $interval));

        try {
            $dispatcher->daemon($interval);
        } catch (\Throwable $e) {
            $this->error('调度守护异常退出：' . $e->getMessage());

            return 1;
        }

        return 0;
    }
}
