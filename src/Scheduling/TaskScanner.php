<?php

declare(strict_types=1);

namespace Kode\Framework\Scheduling;

use Kode\Attributes\Reader;
use Kode\Framework\Scheduling\Attributes\Cron;
use ReflectionClass;

/**
 * 任务扫描器：在约定目录中自动发现 #[Cron] 定时任务。
 *
 * 与属性路由的 {@see \Kode\Framework\Http\ControllerScanner} 同理念——
 * 约定优于配置，零侵入扫描，无需在配置里逐条登记。
 *
 * 支持两种声明：
 *  - 类级 #[Cron('* * * * *')]：调用该类的 handle() 方法；
 *  - 方法级 #[Cron('* * * * *')]：调用该方法（一个类可挂多条任务）。
 *
 * 二者可混用：类上有一个「总任务」+ 方法上挂若干辅助任务。
 */
final class TaskScanner
{
    /** 类级 #[Cron] 缺省调用的方法名。 */
    private const DEFAULT_METHOD = 'handle';

    public function __construct(
        private readonly Reader $reader,
    ) {
    }

    /**
     * 扫描一批目录，返回所有被发现（含未启用）的任务。
     *
     * @param array<string, string> $dirs  key=来源标签（app / plugin:<name>），value=目录绝对路径
     * @return list<ScheduledTask>
     */
    public function scan(array $dirs): array
    {
        $tasks = [];

        foreach ($dirs as $source => $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $tasks = [...$tasks, ...$this->scanDir($dir, (string) $source)];
        }

        return $tasks;
    }

    /**
     * @return list<ScheduledTask>
     */
    private function scanDir(string $dir, string $source): array
    {
        $tasks = [];

        // 复用 kode/attributes 的 Scanner 遍历目录下所有类。
        $scanner = new \Kode\Attributes\Scanner($this->reader, $dir);

        foreach ($scanner->classes($dir) as $class) {
            if (!class_exists($class)) {
                continue;
            }

            $ref = new ReflectionClass($class);
            if ($ref->isAbstract()) {
                continue;
            }

            // ---- 类级 #[Cron]：调用 handle() ----
            $classAttr = $this->reader->getClassAttrs($class)->get(Cron::class);
            if ($classAttr !== null) {
                /** @var Cron $attr */
                $attr = $classAttr->getInstance();
                $this->add($tasks, $class, self::DEFAULT_METHOD, $attr, $source);
            }

            // ---- 方法级 #[Cron]：调用该方法 ----
            foreach ($this->reader->getAllMethodAttrs($class) as $method => $metas) {
                $methodAttr = $metas->get(Cron::class);
                if ($methodAttr === null) {
                    continue;
                }
                /** @var Cron $attr */
                $attr = $methodAttr->getInstance();
                $this->add($tasks, $class, $method, $attr, $source);
            }
        }

        return $tasks;
    }

    /**
     * @param list<ScheduledTask> $tasks
     */
    private function add(array &$tasks, string $class, string $method, Cron $attr, string $source): void
    {
        $ref = new ReflectionClass($class);
        if (!$ref->hasMethod($method)) {
            logger()->warning(
                sprintf('[schedule] 跳过 %s：找不到方法 %s()（#[Cron] 指向的调用目标不存在）', $class, $method)
            );

            return;
        }

        $name = $attr->name
            ?? (new \ReflectionClass($class))->getShortName() . '::' . $method;

        $tasks[] = new ScheduledTask(
            class: $class,
            method: $method,
            expression: $attr->expression,
            name: $name,
            description: $attr->description,
            enabled: $attr->enabled,
            cluster: $attr->cluster,
            source: $source,
        );
    }
}
