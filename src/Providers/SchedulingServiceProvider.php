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
            $dirs = $this->taskDirs();
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

    /**
     * 计算任务目录（key=来源标签，value=绝对路径）。
     *
     * 与 bin/kode 的 cron/schedule:list 命令共用同一约定，避免「命令看得到插件任务、
     * schedule:list 却看不到」的发现口径漂移：两条路径都尊重 discover_plugins。
     *
     * @return array<string, string>
     */
    private function taskDirs(): array
    {
        /** @var array<string, string> $paths */
        $paths = (array) $this->config('schedule.paths', ['app' => 'app/tasks']);

        $dirs = [];
        foreach ($paths as $source => $rel) {
            $path = (string) $rel;
            $dirs[(string) $source] = self::isAbsolute($path) ? $path : $this->basePath($path);
        }

        if ($this->config('schedule.discover_plugins', false)) {
            foreach (glob($this->basePath('plugins/*'), GLOB_ONLYDIR) ?: [] as $plugin) {
                $name = basename($plugin);
                $dirs['plugin:' . $name] = $plugin . '/src/Tasks';
            }
        }

        return $dirs;
    }

    /**
     * 跨平台绝对路径判断（与 bin/kode 的 is_absolute_path() 同语义）。
     *
     * 不复用那个全局函数：它定义在 CLI 入口文件里，服务端/测试入口并不加载，
     * Provider 依赖它会在常驻进程中静默失败。
     */
    private static function isAbsolute(string $path): bool
    {
        if ($path === '' || $path === '/') {
            return true;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path) || str_starts_with($path, '\\\\');
        }

        return str_starts_with($path, '/');
    }
}
