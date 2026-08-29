<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Event\Dispatcher;
use Kode\Framework\Lock\LockAcquired;
use Kode\Framework\Lock\LockManager;
use Kode\Framework\Lock\LockReleased;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * 分布式锁集成测试（真引导框架）。
 *
 * 复用 bootApp() 启动真实应用，验证：
 *  - lock() 助手在引导后解析 LockManager 单例（来自 LockServiceProvider）；
 *  - run() 真路径下获取 → 执行 → 释放；
 *  - 真实事件系统接收到 LockAcquired / LockReleased（经 Provider 注入的派发闭包）。
 *
 * 每个方法独立进程，避免单例 / 事件监听器跨用例串扰。
 */
final class LockIntegrationTest extends TestCase
{
    /** @var array<int, object> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp();
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testLockHelperResolvesSingleton(): void
    {
        self::assertInstanceOf(LockManager::class, lock());
        self::assertSame(lock(), lock(), 'lock() 应返回同一单例');
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testRunExecutesAndReleasesViaRealContainer(): void
    {
        $result = lock()->run('integ:job', static fn () => 'ok', 30);
        self::assertSame('ok', $result);
        self::assertFalse(lock()->isLocked('integ:job'));
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testEventsAreDispatchedThroughFrameworkDispatcher(): void
    {
        /** @var Dispatcher $dispatcher */
        $dispatcher = app()->container->get(Dispatcher::class);
        $dispatcher->listen(LockAcquired::class, function (LockAcquired $e): void {
            $this->events[] = $e;
        });
        $dispatcher->listen(LockReleased::class, function (LockReleased $e): void {
            $this->events[] = $e;
        });

        lock()->acquire('integ:evt', 30);
        lock()->release('integ:evt');

        self::assertCount(2, $this->events);
        self::assertInstanceOf(LockAcquired::class, $this->events[0]);
        self::assertInstanceOf(LockReleased::class, $this->events[1]);
    }
}
