<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Scheduling\ScheduleDispatcher;

/**
 * 列出全部已注册定时任务。
 *
 *   kode console schedule:list
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
    usage: 'schedule:list {--tenant= : 仅列该租户的任务（租户 id，整数）}',
)]
final class ScheduleListCommand extends Command
{
    /** 本命令认识的选项 */
    private const OPTS = ['tenant'];

    protected function handle(): int
    {
        if (($bad = $this->rejectUnknownOptions(self::OPTS)) !== null) {
            return $bad;
        }

        // 在建调度器之前先验参：--tenant=abc 静默变成「看全部租户」是最难发现的那种错
        if (($bad = $this->checkIntOptions(['tenant'], 0)) !== null) {
            return $bad;
        }

        /** @var ScheduleDispatcher $dispatcher */
        $dispatcher = resolve(ScheduleDispatcher::class);

        $tenantId = $this->input->provided('tenant') ? (int) $this->opt('tenant') : 0;
        $tasks = $tenantId > 0 ? $dispatcher->byTenant($tenantId) : $dispatcher->registered();

        if ($tasks === []) {
            $this->warn('无已注册任务：检查 config/schedule.php 的 paths 与 #[Cron] 标注。');

            return 0;
        }

        $rows = [];
        $unreadable = 0;
        foreach ($tasks as $task) {
            $nextRun = $dispatcher->nextRun($task->name);
            // stats() 读不到时会抛（它不再把「一座库没读到」压成「这台系统没跑过任务」），
            // 这里必须把那一行标成「读不到」并计数，而不是让它冒出来打断整张表 ——
            // 也不许顺手回 0：`$stats['total'] > 0` 一判就变成「没有记录」。
            try {
                $stats = $dispatcher->stats($task->name);
                $lastStatus = (int) $stats['total'] > 0 ? $this->lastRunStatus($task->name) : '—';
            } catch (\Throwable) {
                $unreadable++;
                $lastStatus = '!';
            }

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

        // 「读不到」必须单独说一句：只把那一列画成 ! 或 —，看的人会在「没有记录」与
        // 「这座库连不上」之间猜，而这两件事的处置方式完全不同。
        if ($unreadable > 0) {
            $this->warn(sprintf(
                '其中 %d 条任务的执行统计读不到（列显示 !）—— 这不是「没有执行记录」，请检查数据库连接。',
                $unreadable,
            ));
        }

        return 0;
    }

    /**
     * 查询最近一次执行状态（简化显示）。
     *
     * 三种显示必须分得开：`—` = 这条任务没有执行记录，`!` = 这一行读不出来，
     * 其余 = 真状态。facade 上没有 `selectOne()`（`Db::__callStatic` 会把它当成
     * Model 静态方法转发并抛 BadMethodCallException），所以取首行走 `select()`。
     */
    private function lastRunStatus(string $name): string
    {
        try {
            $db = \Kode\Database\Db\Db::class;
            $rows = $db::select(
                "SELECT status, duration_ms FROM kode_schedule_runs WHERE task_name = ? ORDER BY started_at DESC LIMIT 1",
                [$name],
            );

            if ($rows === []) {
                return '—';
            }
            $row = $rows[0];

            $status = match ($row['status']) {
                'success' => '✓',
                'failed' => '✗',
                'skipped' => '⊘',
                default => '?',
            };

            return sprintf('%s %sms', $status, $row['duration_ms']);
        } catch (\Throwable) {
            // 与「没有记录」（上面那条 '—'）分开：! = 这一行读不出来
            return '!';
        }
    }
}
