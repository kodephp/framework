<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Support\Snowflake;
use PHPUnit\Framework\TestCase;

/**
 * Snowflake 分布式 ID 生成器单元测试。
 *
 * 纯 PHP，不依赖 pcntl / 网络，直接验证算法不变量。
 */
final class SnowflakeTest extends TestCase
{
    public function testIdsAreUnique(): void
    {
        $sf = new Snowflake(1, 1);
        $seen = [];
        for ($i = 0; $i < 20000; $i++) {
            $id = $sf->id();
            self::assertArrayNotHasKey($id, $seen, '生成的 ID 出现重复');
            $seen[$id] = true;
        }
    }

    public function testIdsAreMonotonicallyIncreasing(): void
    {
        $sf = new Snowflake(2, 1);
        $prev = 0;
        for ($i = 0; $i < 5000; $i++) {
            $id = $sf->id();
            self::assertGreaterThan($prev, $id, 'ID 未保持递增');
            $prev = $id;
        }
    }

    public function testIdIsPositive64BitInteger(): void
    {
        $sf = new Snowflake(3, 1);
        $id = $sf->id();
        self::assertIsInt($id);
        self::assertGreaterThan(0, $id);
        // 符号位恒为 0 → 必须落在 63 位正整数范围内。
        self::assertLessThanOrEqual(0x7FFFFFFFFFFFFFFF, $id);
    }

    public function testParseRoundTrips(): void
    {
        $sf = new Snowflake(5, 2);
        $id = $sf->id();
        $parts = $sf->parse($id);

        self::assertSame(5, $parts['worker_id']);
        self::assertSame(2, $parts['datacenter_id']);
        self::assertGreaterThan(0, $parts['timestamp']);
        self::assertGreaterThanOrEqual(0, $parts['sequence']);
        self::assertLessThanOrEqual(4095, $parts['sequence']);
    }

    public function testDifferentNodesProduceDisjointIds(): void
    {
        $a = new Snowflake(0, 0);
        $b = new Snowflake(31, 31);

        $onlyA = [];
        $onlyB = [];
        for ($i = 0; $i < 5000; $i++) {
            $onlyA[$a->id()] = true;
            $onlyB[$b->id()] = true;
        }

        // 节点不同 → ID 空间不应相交。
        $intersection = array_intersect_key($onlyA, $onlyB);
        self::assertSame([], $intersection);
    }

    public function testRejectsOutOfRangeWorkerId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Snowflake(32, 0);
    }

    public function testRejectsOutOfRangeDatacenterId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Snowflake(0, 32);
    }

    public function testLargeClockRollbackThrows(): void
    {
        $sf = new Snowflake(1, 1);
        $sf->id();

        // 把内部 lastTimestamp 拨到未来 10s，模拟大幅时钟回拨。
        $ref = new \ReflectionProperty($sf, 'lastTimestamp');
        $ref->setAccessible(true);
        $ref->setValue($sf, (int) (microtime(true) * 1000) + 10000);

        $this->expectException(\RuntimeException::class);
        $sf->id();
    }

    public function testCustomEpochIsHonored(): void
    {
        $epoch = 1700000000000;
        $sf = new Snowflake(0, 0, $epoch);
        $parts = $sf->parse($sf->id());

        // 解析出的绝对时间戳应 >= 自定义 epoch。
        self::assertGreaterThanOrEqual($epoch, $parts['timestamp']);
    }
}
