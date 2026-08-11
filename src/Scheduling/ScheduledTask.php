<?php

declare(strict_types=1);

namespace Kode\Framework\Scheduling;

/**
 * 一条被发现定时任务的不可变描述（扫描结果值对象）。
 *
 * 由 {@see TaskScanner} 产出，交给 {@see ScheduleDispatcher} 注册到运行时定时器。
 */
final class ScheduledTask
{
    /**
     * @param class-string         $class       任务类 FQCN
     * @param string               $method      被调用的方法名（类级默认为 handle）
     * @param string               $expression  cron 表达式
     * @param string               $name        任务名（人类可读，用于展示/日志）
     * @param string|null          $description 任务说明
     * @param bool                 $enabled     是否启用
     * @param bool                 $cluster     是否集群模式（分布式锁保证至多一次）
     * @param string               $source      来源标签（app / plugin:<name>）
     */
    public function __construct(
        public readonly string $class,
        public readonly string $method,
        public readonly string $expression,
        public readonly string $name,
        public readonly ?string $description,
        public readonly bool $enabled,
        public readonly bool $cluster,
        public readonly string $source,
    ) {
    }

    /**
     * 短类名，用于展示。
     */
    public function shortClass(): string
    {
        $parts = explode('\\', $this->class);

        return end($parts);
    }

    /**
     * 调用目标标识，形如 CleanupTask::handle。
     */
    public function target(): string
    {
        return $this->shortClass() . '::' . $this->method;
    }
}
