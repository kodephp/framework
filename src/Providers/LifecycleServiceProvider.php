<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Event\Dispatcher;
use Kode\Framework\Lifecycle\ApplicationBooted;

/**
 * 生命周期事件服务提供者
 *
 * 在应用 boot 完成时派发 {@see ApplicationBooted}，供用户做进程级一次性初始化
 * （预热缓存、注册信号、启动后台协程等）。worker 级钩子（WorkerStarting /
 * WorkerStopping）由 {@see \Kode\Framework\Server\HttpServer} 在 kode/process 的
 * workerStart / workerStop 事件中派发，覆盖启动与优雅停机。
 *
 * 事件系统未就绪时不阻断启动（try/catch）。
 */
final class LifecycleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        try {
            /** @var Dispatcher $dispatcher */
            $dispatcher = $this->container->get(Dispatcher::class);
            $dispatcher->dispatch(new ApplicationBooted(
                $this->basePath(),
                (string) $this->config('app.env', 'local')
            ));
        } catch (\Throwable) {
            // 事件系统未就绪：不阻断启动。
        }
    }
}
