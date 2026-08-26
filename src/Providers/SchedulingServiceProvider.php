<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Attributes\Reader;
use Kode\Framework\Scheduling\ScheduleDispatcher;
use Kode\Framework\Scheduling\TaskScanner;

/**
 * 调度服务提供者（kode/scheduling，薄壳委托）。
 *
 * 此前框架「最大坑」之一：ScheduleDispatcher 与 kode/scheduling 引擎虽已实现
 * （src/Scheduling 已正确委托），但**生产环境从不被实例化**——无 Provider、无命令、
 * 无 boot 接线，导致定时任务根本不会运行（SchedulingTest 直接 new 它，假绿）。
 *
 * 本 Provider 把调度器接进生命周期：
 *  - 按 config/schedule.php 的 `paths` 自动发现 #[Cron] 任务（约定优于配置）；
 *  - 注册进 ScheduleDispatcher 并绑定为单例（命令运行时解析即触发扫描）；
 *  - 集群协调由 ScheduleDispatcher 内部按 config('schedule.cluster.store') 决定。
 */
final class SchedulingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(ScheduleDispatcher::class, function (): ScheduleDispatcher {
            $dispatcher = new ScheduleDispatcher();
            $this->registerTasks($dispatcher);

            return $dispatcher;
        });

        $this->container->alias('schedule', ScheduleDispatcher::class);
    }

    /**
     * 扫描约定目录，把发现的 #[Cron] 任务注册进调度器。
     *
     * 目录缺失 / 扫描异常不应阻断启动（与 QueueServiceProvider 同策略）。
     */
    private function registerTasks(ScheduleDispatcher $dispatcher): void
    {
        try {
            /** @var array<string, string> $paths */
            $paths = (array) $this->config('schedule.paths', ['app' => 'app/tasks']);

            $dirs = [];
            foreach ($paths as $source => $rel) {
                $dirs[(string) $source] = $this->basePath((string) $rel);
            }

            $tasks = (new TaskScanner(new Reader()))->scan($dirs);
            $enabled = $dispatcher->register($tasks);

            if ($enabled > 0) {
                logger()->info(sprintf(
                    '[schedule] 已注册 %d 条启用定时任务（共 %d 条）',
                    $enabled,
                    count($tasks),
                ));
            }
        } catch (\Throwable $e) {
            // 扫描失败（app/tasks 尚未初始化等）不应阻断启动。
            logger()->warning('[schedule] 任务扫描失败：' . $e->getMessage());
        }
    }
}
