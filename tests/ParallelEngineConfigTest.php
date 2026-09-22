<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Application;
use Kode\Parallel\Engine\EngineFactory;
use Kode\Parallel\Engine\SyncEngine;
use Kode\Parallel\Pool\WorkerPool;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * 并行引擎配置接线验证（config `parallel.engine` / `parallel.enabled`）。
 *
 * 修复前这两个键无人读取：engine 只在解析 WorkerPool 时透传（空串会被 EngineFactory 当作
 * 显式引擎名抛「未知引擎: 」），enabled 完全无效（关掉并行仍按探测结果走）。
 *
 * 各用例独立进程：EngineFactory 的强制引擎与 Application 都是进程级状态。
 */
final class ParallelEngineConfigTest extends TestCase
{
    /**
     * @param array<string, mixed> $parallel
     */
    private function boot(array $parallel): void
    {
        if (app() === null) {
            Application::make(\Kode\Framework\Tests\TestCase::SKELETON_ROOT, [
                // 骨架夹具目录下没有 vendor/，显式指向本仓库自动加载器（与 ParallelProviderTest 同源）。
                'parallel' => $parallel + ['bootstrap' => \dirname(__DIR__) . '/vendor/autoload.php'],
            ]);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBlankEngineFallsBackToAutoDetect(): void
    {
        // `.env` 里 PARALLEL_ENGINE= 经 env() 得到的是空串而非 null，必须归一为「自动探测」，
        // 否则解析 parallel.pool 时抛「未知引擎: 」。
        $this->boot(['engine' => '']);

        self::assertNull(EngineFactory::getDefault(), '空串引擎名应归一为自动探测');
        self::assertInstanceOf(WorkerPool::class, app()->container->get(WorkerPool::class));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWhitespaceEngineFallsBackToAutoDetect(): void
    {
        $this->boot(['engine' => '   ']);

        self::assertNull(EngineFactory::getDefault(), '全空白引擎名同样应归一为自动探测');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testExplicitEngineIsHonored(): void
    {
        $this->boot(['engine' => SyncEngine::NAME]);

        self::assertSame(SyncEngine::NAME, EngineFactory::getDefault(), '显式引擎名须写入进程级默认');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDisabledForcesSyncEvenWhenParallelRequested(): void
    {
        // enabled=false 覆盖显式 engine 选择：关了就是关了，且不得因当前环境不支持 parallel 而抛错。
        $this->boot(['enabled' => false, 'engine' => 'parallel']);

        self::assertSame(SyncEngine::NAME, EngineFactory::getDefault());
        self::assertFalse((bool) app()->container->get('parallel.available'));
        self::assertInstanceOf(WorkerPool::class, app()->container->get(WorkerPool::class));
    }
}
