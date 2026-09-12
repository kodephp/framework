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
     * @param class-string         $class       任务类 FQCN（内联闭包任务固定为 Closure，仅供展示）
     * @param string               $method      被调用的方法名（类级默认为 handle；内联闭包为 __invoke）
     * @param string               $expression  cron 表达式
     * @param string               $name        任务名（人类可读，用于展示/日志）
     * @param string|null          $description 任务说明
     * @param bool                 $enabled     是否启用
     * @param bool                 $cluster     是否集群模式（分布式锁保证至多一次）
     * @param string               $source      来源标签（app / plugin:<name>）
     * @param \Closure|null        $handler     内联处理器（插件 addCron() 传闭包时用）；非空时优先于类方法调用
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
        public readonly ?\Closure $handler = null,
    ) {
    }

    /**
     * 是否为内联闭包任务（非 class::method 形式）。
     */
    public function isInline(): bool
    {
        return $this->handler !== null;
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
     * 复制并替换启停状态（值对象不可变，启停必须换新实例）。
     *
     * 供 {@see ScheduleDispatcher::setEnabled()} 在运行时启停某条任务时使用：
     * 插件暂停/恢复不应改写扫描产物，而是替换注册表中的记录。
     */
    public function withEnabled(bool $enabled): self
    {
        return new self(
            class: $this->class,
            method: $this->method,
            expression: $this->expression,
            name: $this->name,
            description: $this->description,
            enabled: $enabled,
            cluster: $this->cluster,
            source: $this->source,
            handler: $this->handler,
        );
    }

    /**
     * 调用目标标识，形如 CleanupTask::handle；内联闭包任务形如 Closure::__invoke。
     */
    public function target(): string
    {
        return $this->shortClass() . '::' . $this->method;
    }
}
