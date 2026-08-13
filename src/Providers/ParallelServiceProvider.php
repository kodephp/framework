<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Providers\ServiceProvider;
use Kode\Parallel\Parallel;
use Kode\Parallel\Pool\WorkerPool;
use Kode\Parallel\Runtime\Runtime;

/**
 * 并行计算服务提供者（kode/parallel，薄壳委托）
 *
 * 此前框架装了 kode/parallel 却未启用（仅注释），并行能力「静默失接」。
 *
 * 本 Provider 把能力接进生命周期：
 *  - 探测可用后端（ZTS + ext-parallel 真线程 → parallel 引擎，否则 sync 同步回退），
 *    并把 bootstrap 默认值 / 可用性写回 config，作为单一事实源；
 *  - 绑定 WorkerPool / Runtime 单例，供 DI 注入；
 *  - 业务侧用 parallel() 助手提交任务（见 src/Support/helpers.php），自动带上 bootstrap
 *    使线程内能加载业务类自动加载器。
 *
 * 非 ZTS 环境自动回退 sync 引擎（单线程顺序执行、API 一致、不报错），代码在任意环境都能跑。
 */
final class ParallelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /** @var array<string, mixed> $config */
        $config = (array) $this->config('parallel', []);

        // 默认 bootstrap：任务闭包内使用业务类所需的自动加载器（通常为 vendor/autoload.php）。
        $bootstrap = (string) ($config['bootstrap'] ?? '');
        if ($bootstrap === '') {
            $bootstrap = $this->basePath('vendor/autoload.php');
        }

        $enabled = ($config['enabled'] ?? true) !== false;
        $available = $enabled && Parallel::isAvailable();

        // 暴露 bootstrap / 可用性到容器，供 parallel() 助手与业务侧诊断（config 加载期尚未就绪，不写回 config）。
        $this->container->instance('parallel.bootstrap', $bootstrap);
        $this->container->instance('parallel.available', $available);

        $engine = $config['engine'] ?? null;
        $concurrency = (int) ($config['concurrency'] ?? 0);

        // 工作池：进程级共享，按并发上限复用线程（sync 回退下退化为顺序执行）。
        $this->container->singleton(WorkerPool::class, static function () use ($concurrency, $engine): WorkerPool {
            return Parallel::pool($concurrency, $engine === null ? null : (string) $engine);
        });
        $this->container->alias('parallel.pool', WorkerPool::class);

        // 共享 Runtime：进程级常驻，parallel() 默认走它。
        $this->container->singleton(Runtime::class, static function () use ($bootstrap, $engine): Runtime {
            return Parallel::shared($bootstrap);
        });
        $this->container->alias('parallel.runtime', Runtime::class);
    }
}
