<?php

declare(strict_types=1);

namespace Kode\Framework\Tests\Fixtures\Tasks;

use Kode\Framework\Scheduling\Attributes\Cron;

/**
 * 测试夹具：位于骨架 plugins/<name>/src/Tasks 下的 #[Cron] 任务。
 *
 * 用于验证 schedule.discover_plugins 开启后，插件目录的任务被纳入调度并标记来源
 * plugin:fixture_plugin。
 *
 * 路径与命名空间故意不一致（命名空间沿用测试夹具命名空间）：TaskScanner 依据文件
 * 内容解析类名而非文件路径；骨架内没有独立 autoloader，因此由 PluginCronTest 在引导前
 * 手动 require_once 本文件——与 tests/Fixtures/Tasks 夹具的加载约定一致。
 */
#[Cron('* * * * *', name: 'fixture-plugin-task', description: '插件目录发现夹具（每分钟）')]
final class PluginDueTask
{
    public function handle(): void
    {
        CronCallTask::$calls[] = 'plugin.due';
    }
}
