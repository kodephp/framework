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
 * 展示每条任务的名称、cron 表达式、启用状态、处理器、来源（app / plugin:<name>）、
 * 租户、下次运行时刻与最近执行状态。
 * 若为空，检查 config/schedule.php 的 paths 与 #[Cron] 标注是否就位。
 *
 * v1.6.0 增强：显示租户、下次运行、最近执行状态（成功/失败/跳过 + 耗时）。
 */
#[AsCommand(
    name: 'schedule:list',
    description: '列出全部已注册定时任务（含下次运行与最近执行状态）',
    usage: 'schedule:list [--tenant=0]',
)]
final class ScheduleListCommand extends Command
{
    protected function handle(): int
    {
        /** @var ScheduleDispatcher $dispatcher */
        $dispatcher = resolve(ScheduleDispatcher::class);

        $tenantId = (int) ($this->opt('tenant') ?? 0);
        $tasks = $tenantId > 0 ? $dispatcher->byTenant($tenantId) : $dispatcher->registered();

        if ($tasks === []) {
            $this->warn('无已注册任务：检查 config/schedule.php 的 paths 与 #[Cron] 标注。');

            return 0;
        }

        $rows = [];
        foreach ($tasks as $task) {
            $nextRun = $dispatcher->nextRun($task->name);
            $stats = $dispatcher->stats($task->name);
            $lastStatus = $stats['total'] > 0 ? $this->lastRunStatus($task->name) : '—';

            $rows[] = [
                $task->name,
                $task->expression,
                $task->enabled ? 'on' : 'off',
                $task->source,
                $task->tenantId > 0 ? "T{$task->tenantId}" : 'global',
                $task->cluster ? 'yes' : 'no',
                $lastStatus,
                $nextRun?->format('m-d H:i:s') ?? '—',
            ];
        }

        $this->table(
            ['Name', 'Cron', 'Enabled', 'Source', 'Tenant', 'Cluster', 'Last', 'Next Run'],
            $rows,
        );

        $this->line(sprintf(
            '共 %d 条任务（启用 %d 条）',
            count($tasks),
            $dispatcher->enabledCount(),
        ));

        return 0;
    }

    /**
     * 查询最近一次执行状态（简化显示）。
     */
    private function lastRunStatus(string $name): string
    {
        try {
            $db = \Kode\Database\Db\Db::class;
            $row = $db::selectOne(
                "SELECT status, duration_ms FROM kode_schedule_runs WHERE task_name = ? ORDER BY started_at DESC LIMIT 1",
                [$name],
            );

            if ($row === null) {
                return '—';
            }

            $status = match ($row['status']) {
                'success' => '✓',
                'failed' => '✗',
                'skipped' => '⊘',
                default => '?',
            };

            return sprintf('%s %sms', $status, $row['duration_ms']);
        } catch (\Throwable) {
            return '—';
        }
    }
}
