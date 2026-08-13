<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Event\Dispatcher;
use Kode\Framework\Lifecycle\WorkerStopping;
use Kode\Framework\Server\GracefulShutdown;

/**
 * 优雅停机服务提供者（薄壳层）。
 *
 * 不重新实现信号/进程编排（kode/process 已负责），仅在 worker 级补齐：
 *  - 绑定 {@see GracefulShutdown} 单例（每 worker 一个，多进程天然隔离）；
 *  - 注册默认清理回调：flush 队列连接、断开 DB 连接（均按「能力是否就绪」门控，
 *    未安装/未绑定对应服务时静默跳过，绝不因收尾动作反向拖垮停机）；
 *  - 监听 {@see WorkerStopping} 事件，在 kode/process 排空后统一执行清理。
 *
 * 业务如需额外收尾（注册中心下线、指标落盘、文件锁释放等），可自行
 * `resolve(GracefulShutdown::class)->registerCleanup(fn () => ...)`，
 * 或在 config/event.php 的 listeners 里追加 WorkerStopping 监听器。
 */
final class GracefulShutdownServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(GracefulShutdown::class, fn(): GracefulShutdown => new GracefulShutdown());
        $this->container->alias('graceful', GracefulShutdown::class);
    }

    public function boot(): void
    {
        /** @var GracefulShutdown $manager */
        $manager = $this->container->get(GracefulShutdown::class);

        // 默认清理：队列连接 flush（防内存中攒批任务在进程退出前丢失）。
        $manager->registerCleanup(static function (): void {
            if (app() === null || !app()->container->bound('queue')) {
                return;
            }

            $queue = queue();
            if (method_exists($queue, 'close')) {
                $queue->close();
            }
        });

        // 默认清理：数据库/缓存连接断开（进程退出前主动释放，避免孤儿连接）。
        $manager->registerCleanup(static function (): void {
            if (app() === null || !app()->container->bound('db')) {
                return;
            }

            $db = db();
            if (method_exists($db, 'disconnect')) {
                $db->disconnect();
            }
        });

        // worker 停止时机统一收尾（kode/process 排空在途连接后触发）。
        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->container->get(Dispatcher::class);
        $dispatcher->listen(WorkerStopping::class, static function (WorkerStopping $event) use ($manager): void {
            $manager->shutdown();
        });
    }
}
