<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Application;
use Kode\Parallel\Parallel;
use Kode\Parallel\Pool\WorkerPool;
use Kode\Parallel\Runtime\Runtime;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * 并行计算（P1）接线验证：
 *  - ParallelServiceProvider 绑定 WorkerPool / Runtime 单例；
 *  - 可用性探测结果为 bool（非 ZTS 环境自动回退 sync 引擎，API 一致）；
 *  - parallel() 助手能提交任务并取到结果（sync 回退下同步执行）。
 */
/**
 * 各用例均独立进程运行：parallel 的引导文件在 boot 时即固定于容器，而 Application 是
 * 进程级单例，若被先跑的用例引导过，本类的 config 覆盖将失效。
 */
final class ParallelProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (app() === null) {
            // 骨架夹具目录下没有 vendor/，而 kode/parallel 的引导文件默认是
            // basePath('vendor/autoload.php')——此处显式指向仓库自身的自动加载器。
            Application::make(\Kode\Framework\Tests\TestCase::SKELETON_ROOT, [
                'parallel' => ['bootstrap' => dirname(__DIR__) . '/vendor/autoload.php'],
            ]);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPoolAndRuntimeBound(): void
    {
        self::assertInstanceOf(WorkerPool::class, app()->container->get(WorkerPool::class));
        self::assertInstanceOf(Runtime::class, app()->container->get(Runtime::class));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAvailabilityIsBool(): void
    {
        self::assertIsBool(app()->container->get('parallel.available'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBootstrapResolved(): void
    {
        $bootstrap = app()->container->get('parallel.bootstrap');
        self::assertIsString($bootstrap);
        self::assertFileExists($bootstrap);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testParallelHelperRunsTask(): void
    {
        // sync 回退引擎同步执行，await 直接返回结果；ZTS 环境下则为真实异步 future。
        $future = parallel(static fn(array $a): int => $a['x'] * 2, ['x' => 21]);

        self::assertSame(42, Parallel::await($future));
    }
}
