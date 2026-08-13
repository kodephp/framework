<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Scheduling\ScheduleDispatcher;

/**
 * 列出全部已注册定时任务。
 *
 *   bin/kode console schedule:list
 *
 * 展示每条任务的名称、cron 表达式、启用状态、处理器与来源（app / plugin:<name>）。
 * 若为空，检查 config/schedule.php 的 paths 与 #[Cron] 标注是否就位。
 */
#[AsCommand(
    name: 'schedule:list',
    description: '列出全部已注册定时任务',
    usage: 'schedule:list',
)]
final class ScheduleListCommand extends Command
{
    protected function handle(): int
    {
        /** @var ScheduleDispatcher $dispatcher */
        $dispatcher = resolve(ScheduleDispatcher::class);

        $tasks = $dispatcher->registered();

        if ($tasks === []) {
            $this->warn('无已注册任务：检查 config/schedule.php 的 paths 与 #[Cron] 标注。');

            return 0;
        }

        $rows = [];
        foreach ($tasks as $task) {
            $rows[] = [
                $task->name,
                $task->expression,
                $task->enabled ? 'on' : 'off',
                $task->class . '::' . $task->method,
                $task->source,
            ];
        }

        $this->table(['Name', 'Cron', 'Enabled', 'Handler', 'Source'], $rows);

        return 0;
    }
}
