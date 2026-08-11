<?php

declare(strict_types=1);

namespace Kode\Framework\Scheduling\Attributes;

use Attribute;

/**
 * 任务调度属性：标记一个类（或类中的某个方法）为定时任务。
 *
 * 使用方式（与属性路由同理念——约定优于配置，零侵入自动发现）：
 *
 * ```php
 * // 1) 类级：类上声明表达式，框架调用其 handle() 方法
 * #[Cron('0 0 * * *', name: 'nightly-cleanup', description: '每天 0 点清理')]
 * class CleanupTask extends Task
 * {
 *     public function handle(): void { /* ... *\/ }
 * }
 *
 * // 2) 方法级：单个方法即一条任务，可在一个类里写多条
 * #[Cron('0,30 * * * *')]
 * public function ping(): void { /* ... *\/ }
 * ```
 *
 * 扫描目录由 config/schedule.php 的 paths 控制；开启 discover_plugins
 * 后插件目录中的 #[Cron] 任务也会被纳入，并标记来源 plugin:<name>。
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Cron
{
    public function __construct(
        /**
         * 标准 5 段 cron 表达式（分 时 日 月 周）。
         * 支持字段名（jan-dec / sun-sat，同 kode/process Crontab）。
         */
        public string $expression,
        /**
         * 任务名（可选，便于 schedule:list 展示与日志）。
         * 类级未指定时回退为「短类名::handle」；方法级回退为「短类名::方法名」。
         */
        public ?string $name = null,
        /** 任务说明（可选，仅用于 schedule:list 展示）。 */
        public ?string $description = null,
        /** 是否启用（默认 true；设为 false 可在不删代码的前提下临时停掉某任务）。 */
        public bool $enabled = true,
        /**
         * 集群模式：true 时使用 Kode::cronCluster()（分布式锁，保证多进程/多机
         * 同一调度时刻至多执行一次）。前提：已配置协调存储（Cluster::make('redis'|'file'...)）。
         */
        public bool $cluster = false,
    ) {
    }
}
