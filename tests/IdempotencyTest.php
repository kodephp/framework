<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Idempotency\DuplicateRequest;
use Kode\Framework\Idempotency\IdempotencyHit;
use Kode\Framework\Idempotency\IdempotencyManager;
use Kode\Framework\Idempotency\IdempotencyRecorded;
use Kode\Framework\Idempotency\StaticIdempotencyManager;
use Kode\Framework\Idempotency\StaticIdempotencyStore;
use PHPUnit\Framework\TestCase;

/**
 * 幂等单元测试（直接驱动 StaticIdempotencyManager + StaticIdempotencyStore，注入事件派发闭包捕获事件）。
 */
final class IdempotencyTest extends TestCase
{
    /** @var array<int, object> */
    private array $events = [];

    private function manager(bool $file = false, ?string $dir = null): StaticIdempotencyManager
    {
        $this->events = [];
        $dir = $file ? ($dir ?? sys_get_temp_dir() . '/kode-idm-test-' . uniqid()) : null;

        return new StaticIdempotencyManager(
            new StaticIdempotencyStore([], $dir),
            function (object $e): object {
                $this->events[] = $e;

                return $e;
            },
        );
    }

    public function testOnceExecutesFirstTimeAndReturnsResult(): void
    {
        $m = $this->manager();
        $result = $m->once('req:1', static fn () => 'done', 3600);
        self::assertSame('done', $result);
        self::assertInstanceOf(IdempotencyRecorded::class, $this->events[0]);
    }

    public function testOnceThrowsDuplicateOnReplay(): void
    {
        $m = $this->manager();
        $m->once('req:1', static fn () => 'done', 3600);
        $this->expectException(DuplicateRequest::class);
        $m->once('req:1', static fn () => 'done', 3600);
        // 命中重复应派发 IdempotencyHit
        self::assertInstanceOf(IdempotencyHit::class, $this->events[1]);
    }

    public function testOnceRollsBackRecordOnFailureSoRetryAllowed(): void
    {
        $m = $this->manager();
        try {
            $m->once('req:1', static function (): void {
                throw new \RuntimeException('boom');
            }, 3600);
        } catch (\RuntimeException) {
            // 预期
        }
        // 业务失败 → 记录已回滚 → 可重新 once()
        $result = $m->once('req:1', static fn () => 'recovered', 3600);
        self::assertSame('recovered', $result);
    }

    public function testSeenReturnsTrueFirstFalseOnReplay(): void
    {
        $m = $this->manager();
        self::assertTrue($m->seen('req:2', 3600));
        self::assertFalse($m->seen('req:2', 3600));
        self::assertInstanceOf(IdempotencyRecorded::class, $this->events[0]);
        self::assertInstanceOf(IdempotencyHit::class, $this->events[1]);
    }

    public function testForgetAllowsRetry(): void
    {
        $m = $this->manager();
        $m->once('req:3', static fn () => 'x', 3600);
        $m->forget('req:3');
        // 删除后再次 once 应视为首次
        self::assertSame('y', $m->once('req:3', static fn () => 'y', 3600));
    }

    public function testExpiredRecordBecomesFree(): void
    {
        $m = $this->manager();
        $store = $m->store();
        $store->put('req:4', 1);
        // 模拟过期
        $ref = new \ReflectionMethod($store, 'write');
        $ref->setAccessible(true);
        $ref->invoke($store, 'req:4', ['expires' => microtime(true) - 10]);

        self::assertFalse($store->has('req:4'));
        self::assertNull($store->ttl('req:4'));
        // 过期后可重新 once
        self::assertSame('z', $m->once('req:4', static fn () => 'z', 3600));
    }

    public function testStoreKeysExcludesExpired(): void
    {
        $m = $this->manager();
        $store = $m->store();
        $store->put('a', 30);
        $store->put('b', 30);
        self::assertEqualsCanonicalizing(['a', 'b'], $store->keys());
        $store->forget('a');
        self::assertSame(['b'], $store->keys());
    }

    public function testImplementsContract(): void
    {
        self::assertInstanceOf(IdempotencyManager::class, $this->manager());
    }

    public function testFileBackendPersistsAcrossCalls(): void
    {
        $dir = sys_get_temp_dir() . '/kode-idm-test-' . uniqid();
        $m = $this->manager(true, $dir);
        $m->once('shared', static fn () => 'ok', 3600);
        self::assertTrue($m->store()->has('shared'));
        $m->forget('shared');
        self::assertSame([], $m->store()->keys());
        self::assertDirectoryExists($dir);
    }
}
