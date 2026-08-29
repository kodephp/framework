<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Application;
use Kode\Framework\Server\GracefulShutdown;
use PHPUnit\Framework\TestCase;

/**
 * 优雅停机管理器（生产级增强）验证：
 *  - track() 正确计入/计出在途请求，且透传 handler 返回值；
 *  - track() 在停机流程中、最后一个在途请求完成时自动触发清理；
 *  - registerCleanup + shutdown() 执行清理，且幂等（重复调用不重复执行）；
 *  - GracefulShutdownServiceProvider 已绑定单例与 'graceful' 别名。
 */
final class GracefulShutdownTest extends TestCase
{
    public function testTrackCountsInFlightAndReturnsValue(): void
    {
        $m = new GracefulShutdown();

        $this->assertSame(0, $m->inFlight());

        $result = $m->track(static function () use ($m): string {
            // 进入 handler 时计数为 1
            TestCase::assertSame(1, $m->inFlight());
            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(0, $m->inFlight());
    }

    public function testTrackDecrementsEvenWhenHandlerThrows(): void
    {
        $m = new GracefulShutdown();

        try {
            $m->track(static function (): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('expected exception to propagate');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, $m->inFlight());
    }

    public function testShutdownRunsRegisteredCleanupsAndIsIdempotent(): void
    {
        $m = new GracefulShutdown();

        $calls = 0;
        $m->registerCleanup(static function () use (&$calls): void {
            ++$calls;
        });

        $m->shutdown();
        // 无在途请求：立即清理
        $this->assertSame(1, $calls);
        $this->assertTrue($m->isCleanedUp());

        // 重复 shutdown 不应重复执行清理（幂等）
        $m->shutdown();
        $this->assertSame(1, $calls);
    }

    public function testTrackTriggersShutdownWhenLastInFlightDuringShutdown(): void
    {
        $m = new GracefulShutdown();

        $cleanups = 0;
        $m->registerCleanup(static function () use (&$cleanups): void {
            ++$cleanups;
        });

        // 模拟「已进入停机、但尚有在途请求」：手动翻转 shuttingDown 标志后 track。
        // 用反射设置 shuttingDown=true，使 track 的 finally 在最后一个请求完成时触发清理。
        $ref = new \ReflectionProperty(GracefulShutdown::class, 'shuttingDown');
        $ref->setAccessible(true);
        $ref->setValue($m, true);

        $m->track(static function (): void {
            // 在途中
        });

        // 最后一个在途请求完成 → 自动清理
        $this->assertSame(1, $cleanups);
        $this->assertSame(0, $m->inFlight());
    }

    public function testCleanupExceptionDoesNotBlockShutdown(): void
    {
        $m = new GracefulShutdown();

        $ran = 0;
        $m->registerCleanup(static function () use (&$ran): void {
            ++$ran;
            throw new \RuntimeException('cleanup failed');
        });
        $m->registerCleanup(static function () use (&$ran): void {
            ++$ran;
        });

        // 清理回调抛异常不应阻断后续清理，也不应抛出到调用方
        $m->shutdown();

        $this->assertSame(2, $ran);
        $this->assertTrue($m->isCleanedUp());
    }

    public function testProviderBindsSingletonAndAlias(): void
    {
        if (app() === null) {
            Application::make(\Kode\Framework\Tests\TestCase::SKELETON_ROOT);
        }

        $a = graceful();
        $b = graceful();

        $this->assertInstanceOf(GracefulShutdown::class, $a);
        // 同一 worker 内为单例
        $this->assertSame($a, $b);
    }
}
