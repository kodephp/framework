<?php

declare(strict_types=1);

namespace Kode\Framework\Scheduling;

/**
 * 定时任务基类（可选）。
 *
 * 约定：类上用 #[Cron(...)] 声明 cron 表达式，并实现 handle() 作为任务入口。
 * 不继承本类也能工作（只要类上有 #[Cron] 且存在 handle() 方法），继承本类
 * 仅为获得类型明确的 handle() 签名与统一的「任务」语义。
 *
 * ```php
 * #[Cron('0 0 * * *', name: 'nightly-cleanup', description: '每天 0 点清理过期数据')]
 * class CleanupTask extends Task
 * {
 *     public function handle(): void
 *     {
 *         // 业务逻辑；构造依赖可通过容器自动注入。
 *     }
 * }
 * ```
 */
abstract class Task
{
    /**
     * 任务入口。由调度器按 cron 表达式周期调用。
     */
    abstract public function handle(): void;
}
