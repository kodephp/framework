<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Event\Dispatcher;
use Kode\Framework\Idempotency\IdempotencyHit;
use Kode\Framework\Idempotency\IdempotencyManager;
use Kode\Framework\Idempotency\IdempotencyRecorded;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * 幂等集成测试（真引导框架）。
 *
 * 复用 bootApp() 启动真实应用，验证：
 *  - idempotency() 助手在引导后解析 IdempotencyManager 单例（来自 IdempotencyServiceProvider）；
 *  - once() 真路径下首次执行、重放抛 DuplicateRequest；
 *  - 真实事件系统接收到 IdempotencyRecorded / IdempotencyHit（经 Provider 注入的派发闭包）。
 *
 * 每个方法独立进程，避免单例 / 事件监听器跨用例串扰。
 */
final class IdempotencyIntegrationTest extends TestCase
{
    /** @var array<int, object> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp();
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testHelperResolvesSingleton(): void
    {
        self::assertInstanceOf(IdempotencyManager::class, idempotency());
        self::assertSame(idempotency(), idempotency(), 'idempotency() 应返回同一单例');
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testOnceExecutesThenThrowsDuplicateOnReplay(): void
    {
        self::assertSame('ok', idempotency()->once('integ:req', static fn () => 'ok', 3600));

        $this->expectException(\Kode\Framework\Idempotency\DuplicateRequest::class);
        idempotency()->once('integ:req', static fn () => 'ok', 3600);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testEventsAreDispatchedThroughFrameworkDispatcher(): void
    {
        /** @var Dispatcher $dispatcher */
        $dispatcher = app()->container->get(Dispatcher::class);
        $dispatcher->listen(IdempotencyRecorded::class, function (IdempotencyRecorded $e): void {
            $this->events[] = $e;
        });
        $dispatcher->listen(IdempotencyHit::class, function (IdempotencyHit $e): void {
            $this->events[] = $e;
        });

        idempotency()->once('integ:evt', static fn () => null, 3600);
        try {
            idempotency()->once('integ:evt', static fn () => null, 3600);
        } catch (\Kode\Framework\Idempotency\DuplicateRequest) {
            // 预期重复
        }

        self::assertCount(2, $this->events);
        self::assertInstanceOf(IdempotencyRecorded::class, $this->events[0]);
        self::assertInstanceOf(IdempotencyHit::class, $this->events[1]);
    }
}
