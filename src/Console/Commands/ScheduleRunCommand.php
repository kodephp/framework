<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Scheduling\ScheduleDispatcher;

/**
 * 运行当前到期的一次性调度轮。
 *
 *   bin/kode console schedule:run        # 运行此刻到期的任务（对应 crontab 的 * * * * *）
 *
 * 通常由系统 crontab 每分钟触发；也可在 CI / 一次性补跑时显式调用。
 * 退出码：全部成功 0，存在失败 1（便于 crontab 监控告警）。
 */
#[AsCommand(
    name: 'schedule:run',
    description: '运行当前到期的一次性调度轮（通常由系统 crontab 每分钟触发）',
    usage: 'schedule:run',
)]
final class ScheduleRunCommand extends Command
{
    protected function handle(): int
    {
        /** @var ScheduleDispatcher $dispatcher */
        $dispatcher = resolve(ScheduleDispatcher::class);

        $report = $dispatcher->run();

        $this->line(sprintf(
            '调度轮完成：成功 %d，失败 %d，跳过 %d。',
            $report->succeededCount(),
            $report->failedCount(),
            $report->skippedCount(),
        ));

        return $report->allSucceeded() ? 0 : 1;
    }
}
