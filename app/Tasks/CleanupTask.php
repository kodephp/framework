<?php

declare(strict_types=1);

namespace App\Tasks;

use Kode\Framework\Scheduling\Attributes\Cron;
use Kode\Framework\Scheduling\Task;

/**
 * 示例定时任务：类级 #[Cron] 声明，每天 0 点执行一次。
 *
 * 运行：bin/kode cron   （常驻调度器会自动发现并执行）
 * 查看：bin/kode schedule:list
 */
#[Cron('0 0 * * *', name: 'nightly-cleanup', description: '每天 0 点清理过期缓存与临时文件')]
final class CleanupTask extends Task
{
    public function handle(): void
    {
        // 这里写真实业务逻辑，例如清理过期缓存、归档日志等。
        logger()->info('[task] 执行每日清理：清理过期缓存与临时文件');
    }
}
