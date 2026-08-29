<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Event\Dispatcher;
use Kode\Fibers\Fibers;
use Kode\Framework\Resilience\Events\TimeoutExceeded as TimeoutExceededEvent;
use Kode\Framework\Resilience\Timeout;
use Kode\Framework\Resilience\TimeoutExceeded;

/**
 * 超时集成测试（真引导框架）。
 *
 * 复用 bootApp() 启动真实应用，验证：
 *  - timeout() 助手在引导后走容器中的 Timeout 单例（来自 ResilienceServiceProvider）；
 *  - fiber 后端对「会挂起」的任务真实抢占（协程内 sleep 0.5s，预算 0.05s 即超时）；
 *  - 真实事件系统接收到 TimeoutExceeded（经 Provider 注入的 event() 闭包）。
 *
 * 每个方法独立进程，避免单例 / 事件监听器跨用例串扰。
 */
final class TimeoutIntegrationTest extends TestCase
{
    /** @var array<int, object> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp();
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testTimeoutSingletonResolvesFromContainer(): void
    {
        self::assertInstanceOf(Timeout::class, resolve(Timeout::class));
        self::assertSame(resolve(Timeout::class), resolve(Timeout::class), 'Timeout 应为同一单例');
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testTimeoutHelperPreemptsSuspendingOp(): void
    {
        if (!class_exists(Fibers::class)) {
            self::markTestSkipped('kode/fibers 不可用，跳过 fiber 抢占验证');
        }

        $this->expectException(TimeoutExceeded::class);
        timeout(static function (): string {
            Fibers::sleep(0.5); // 协程内挂起，远超预算

            return 'slow';
        }, ['seconds' => 0.05, 'label' => 'downstream']);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testTimeoutEventDispatchedThroughFrameworkDispatcher(): void
    {
        if (!class_exists(Fibers::class)) {
            self::markTestSkipped('kode/fibers 不可用，跳过 fiber 抢占验证');
        }

        /** @var Dispatcher $dispatcher */
        $dispatcher = app()->container->get(Dispatcher::class);
        $dispatcher->listen(TimeoutExceededEvent::class, function (TimeoutExceededEvent $e): void {
            $this->events[] = $e;
        });

        try {
            timeout(static function (): void {
                Fibers::sleep(0.5);
            }, ['seconds' => 0.05, 'label' => 'integ']);
            self::fail('应抛出 TimeoutExceeded');
        } catch (TimeoutExceeded) {
            // 预期
        }

        self::assertCount(1, $this->events);
        self::assertInstanceOf(TimeoutExceededEvent::class, $this->events[0]);
        self::assertSame('integ', $this->events[0]->label);
        self::assertSame(0.05, $this->events[0]->seconds);
    }
}
