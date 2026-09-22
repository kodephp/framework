<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Providers\ServiceProvider;
use Kode\Parallel\Engine\EngineFactory;
use Kode\Parallel\Engine\SyncEngine;
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
 *    并把 bootstrap / 可用性暴露到容器（config 加载期 app() 尚未就绪，不写回 config）；
 *  - 按 config 的 enabled / engine 设定进程级默认引擎，使 parallel() 助手与注入的
 *    WorkerPool / Runtime 走同一套选择（此前 enabled、engine 两键无人读取）；
 *  - 绑定 WorkerPool / Runtime 单例，供 DI 注入；
 *  - 业务侧用 parallel() 助手提交任务（见 src/Support/helpers.php），自动带上 bootstrap
 *    使线程内能加载业务类自动加载器。
 *
 * 非 ZTS 环境自动回退 sync 引擎（单线程顺序执行、API 一致、不报错），代码在任意环境都能跑；
 * 显式指定 engine='parallel' 而当前环境不支持时，在首次使用处抛明确异常（不静默改引擎）。
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

        // 引擎名归一：null / '' / 全空白 → 自动探测。`.env` 里 `PARALLEL_ENGINE=` 经 env()
        // 得到空串而非 null，直传 EngineFactory 会被当作显式引擎名抛「未知引擎: 」。
        $raw = $config['engine'] ?? null;
        $engine = is_string($raw) ? trim($raw) : $raw;
        $engine = ($engine === '' || $engine === null) ? null : (string) $engine;

        // 关闭并行 = 强制 sync（覆盖显式 engine 选择：关了就是关了）：助手路径与注入的
        // pool / Runtime 共用同一判定，避免「配置已关但 parallel() 仍起线程」。
        if (!$enabled) {
            $engine = SyncEngine::NAME;
        }

        // 进程级默认引擎（null 恢复自动探测）：共享 Runtime 由 Parallel::run()/shared_runtime()
        // 使用，若不在此设定，只有注入的 pool 尊重配置、助手路径绕过配置。
        EngineFactory::setDefault($engine);

        // 暴露 bootstrap / 可用性到容器，供 parallel() 助手与业务侧诊断（config 加载期尚未就绪，不写回 config）。
        // 生效引擎名用 Parallel::engine() 现场查，避免在此复制一份可能漂移的状态。
        $this->container->instance('parallel.bootstrap', $bootstrap);
        $this->container->instance('parallel.available', $available);

        $concurrency = (int) ($config['concurrency'] ?? 0);

        // 工作池：进程级共享，按并发上限复用线程（sync 回退下退化为顺序执行）。
        $this->container->singleton(WorkerPool::class, static function () use ($concurrency, $engine): WorkerPool {
            return Parallel::pool($concurrency, $engine);
        });
        $this->container->alias('parallel.pool', WorkerPool::class);

        // 共享 Runtime：进程级常驻，parallel() 默认走它（引擎已由上面的 setDefault 决定）。
        $this->container->singleton(Runtime::class, static function () use ($bootstrap): Runtime {
            return Parallel::shared($bootstrap);
        });
        $this->container->alias('parallel.runtime', Runtime::class);
    }
}
