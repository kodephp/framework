<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Lock\LockAcquired;
use Kode\Framework\Lock\LockAcquireException;
use Kode\Framework\Lock\LockManager;
use Kode\Framework\Lock\LockReleased;
use Kode\Framework\Lock\StaticLockManager;
use PHPUnit\Framework\TestCase;

/**
 * 分布式锁单元测试（直接驱动内置 StaticLockManager，注入事件派发闭包捕获事件）。
 */
final class LockTest extends TestCase
{
    /** @var array<int, object> */
    private array $events = [];

    private function manager(bool $file = false, ?string $dir = null): StaticLockManager
    {
        $this->events = [];

        return new StaticLockManager(
            [],
            $file ? ($dir ?? sys_get_temp_dir() . '/kode-lock-test-' . uniqid()) : null,
            function (object $e): object {
                $this->events[] = $e;

                return $e;
            },
        );
    }

    public function testAcquireReturnsTrueAndDispatchesAcquired(): void
    {
        $m = $this->manager();
        self::assertTrue($m->acquire('order:1'));
        self::assertTrue($m->isLocked('order:1'));
        self::assertCount(1, $this->events);
        self::assertInstanceOf(LockAcquired::class, $this->events[0]);
        self::assertSame('order:1', $this->events[0]->key);
    }

    public function testAcquireFailsWhenHeldByOther(): void
    {
        $m = $this->manager();
        self::assertTrue($m->acquire('order:1', 30, 'owner-A'));
        // 不同 owner 无法获取
        self::assertFalse($m->acquire('order:1', 30, 'owner-B'));
        self::assertTrue($m->isLocked('order:1'));
    }

    public function testSameOwnerReentrantRefreshesTtl(): void
    {
        $m = $this->manager();
        self::assertTrue($m->acquire('order:1', 10, 'owner-A'));
        // 同 owner 重入成功（刷新 TTL）
        self::assertTrue($m->acquire('order:1', 60, 'owner-A'));
        self::assertGreaterThanOrEqual(50, $m->ttl('order:1'));
    }

    public function testReleaseOnlyByOwner(): void
    {
        $m = $this->manager();
        $m->acquire('order:1', 30, 'owner-A');
        // 不同 owner 释放失败
        self::assertFalse($m->release('order:1', 'owner-B'));
        self::assertTrue($m->isLocked('order:1'));
        // 正确 owner 释放成功并派发 Released
        self::assertTrue($m->release('order:1', 'owner-A'));
        self::assertFalse($m->isLocked('order:1'));
        self::assertInstanceOf(LockReleased::class, $this->events[1]);
        self::assertFalse($this->events[1]->forced);
    }

    public function testExpiredLockBecomesFree(): void
    {
        $m = $this->manager();
        $m->acquire('order:1', 1, 'owner-A');
        // 模拟过期：直接写入过去的时间戳
        $ref = new \ReflectionMethod($m, 'write');
        $ref->setAccessible(true);
        $ref->invoke($m, 'order:1', ['owner' => 'owner-A', 'expires' => microtime(true) - 10]);

        self::assertFalse($m->isLocked('order:1'));
        self::assertNull($m->owner('order:1'));
        self::assertNull($m->ttl('order:1'));
        // 过期后可被他人获取
        self::assertTrue($m->acquire('order:1', 30, 'owner-B'));
    }

    public function testForceReleaseIgnoresOwnerAndMarksForced(): void
    {
        $m = $this->manager();
        $m->acquire('order:1', 30, 'owner-A');
        self::assertTrue($m->forceRelease('order:1'));
        self::assertFalse($m->isLocked('order:1'));
        // 最后一个事件是 forced Released
        $last = $this->events[count($this->events) - 1];
        self::assertInstanceOf(LockReleased::class, $last);
        self::assertTrue($last->forced);
    }

    public function testKeysExcludesExpired(): void
    {
        $m = $this->manager();
        $m->acquire('a', 30);
        $m->acquire('b', 30);
        self::assertEqualsCanonicalizing(['a', 'b'], $m->keys());
        $m->release('a');
        self::assertSame(['b'], $m->keys());
    }

    public function testRunExecutesAndReleases(): void
    {
        $m = $this->manager();
        $captured = null;
        $result = $m->run('job:1', function () use (&$captured) {
            $captured = 'done';

            return 42;
        }, 30);

        self::assertSame(42, $result);
        self::assertSame('done', $captured);
        self::assertFalse($m->isLocked('job:1'));
    }

    public function testRunThrowsWhenLocked(): void
    {
        $m = $this->manager();
        $m->acquire('job:1', 30, 'owner-A');
        $this->expectException(LockAcquireException::class);
        $m->run('job:1', static fn () => null, 30);
    }

    public function testRunReleasesEvenOnException(): void
    {
        $m = $this->manager();
        try {
            $m->run('job:1', static function (): void {
                throw new \RuntimeException('boom');
            }, 30);
        } catch (\RuntimeException) {
            // 预期
        }
        self::assertFalse($m->isLocked('job:1'));
    }

    public function testImplementsContract(): void
    {
        self::assertInstanceOf(LockManager::class, $this->manager());
    }

    public function testFileBackendCrossProcessShape(): void
    {
        $dir = sys_get_temp_dir() . '/kode-lock-test-' . uniqid();
        $m = $this->manager(true, $dir);
        self::assertTrue($m->acquire('shared', 30));
        self::assertTrue($m->isLocked('shared'));
        self::assertTrue($m->release('shared'));
        self::assertDirectoryExists($dir);
        self::assertSame([], $m->keys());
    }
}
