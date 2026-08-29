<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Event\Dispatcher;
use Kode\Framework\Resilience\Backoff\FixedBackoff;
use Kode\Framework\Resilience\Events\RetryExhausted as RetryExhaustedEvent;
use Kode\Framework\Resilience\Events\RetrySucceeded;
use Kode\Framework\Resilience\Retry;
use Kode\Framework\Resilience\RetryExhausted;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * 重试集成测试（真引导框架）。
 *
 * 复用 bootApp() 启动真实应用，验证：
 *  - retry() 助手在引导后走容器中的 Retry 单例（来自 ResilienceServiceProvider）；
 *  - 重试编排真实生效（多次失败 → 恢复）；
 *  - 真实事件系统接收到 RetrySucceeded / RetryExhausted（经 Provider 注入的 event() 闭包）。
 *
 * 每个方法独立进程，避免单例 / 事件监听器跨用例串扰。
 */
final class RetryIntegrationTest extends TestCase
{
    /** @var array<int, object> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp();
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testRetrySingletonResolvesFromContainer(): void
    {
        self::assertInstanceOf(Retry::class, resolve(Retry::class));
        self::assertSame(resolve(Retry::class), resolve(Retry::class), 'Retry 应为同一单例');
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testRetryHelperRetriesThenSucceeds(): void
    {
        $n = 0;
        $result = retry(static function () use (&$n): string {
            $n++;
            if ($n < 3) {
                throw new \RuntimeException('transient');
            }

            return 'recovered';
        }, ['attempts' => 3, 'backoff' => new FixedBackoff(0.0)]);

        self::assertSame('recovered', $result);
        self::assertSame(3, $n, '应实际尝试 3 次（2 次失败 + 1 次成功）');
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testRetryEventsDispatchedThroughFrameworkDispatcher(): void
    {
        /** @var Dispatcher $dispatcher */
        $dispatcher = app()->container->get(Dispatcher::class);
        $dispatcher->listen(RetrySucceeded::class, function (RetrySucceeded $e): void {
            $this->events[] = $e;
        });
        $dispatcher->listen(RetryExhaustedEvent::class, function (RetryExhaustedEvent $e): void {
            $this->events[] = $e;
        });

        // 成功路径：第 2 次恢复 → 派发 RetrySucceeded
        $n = 0;
        retry(static function () use (&$n): void {
            $n++;
            if ($n < 2) {
                throw new \RuntimeException('x');
            }
        }, ['attempts' => 3, 'backoff' => new FixedBackoff(0.0)]);

        self::assertCount(1, $this->events);
        self::assertInstanceOf(RetrySucceeded::class, $this->events[0]);

        // 失败路径：耗尽 → 派发 RetryExhausted
        $this->events = [];
        try {
            retry(static function (): void {
                throw new \RuntimeException('down');
            }, ['attempts' => 2, 'backoff' => new FixedBackoff(0.0)]);
            self::fail('应抛出 RetryExhausted');
        } catch (RetryExhausted) {
            // 预期
        }
        self::assertCount(1, $this->events);
        self::assertInstanceOf(RetryExhaustedEvent::class, $this->events[0]);
    }
}
