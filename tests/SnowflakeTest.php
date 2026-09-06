<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Support\Snowflake;
use Kode\Framework\Providers\SnowflakeServiceProvider;
use Kode\Process\Exceptions\ClusterException;
use Kode\Process\Cluster\Snowflake as ClusterSnowflake;
use PHPUnit\Framework\TestCase;

/**
 * Snowflake 分布式 ID（薄适配）单元测试。
 *
 * 算法由 kode/process 的 Cluster/Snowflake 提供；本测试验证框架适配层
 * （Support/Snowflake 的 id()/parse() 命名对齐）与端到端不变量。
 */
final class SnowflakeTest extends TestCase
{
    private function make(int $workerId, int $epoch = 1704067200000): Snowflake
    {
        return new Snowflake(new ClusterSnowflake($workerId, $epoch));
    }

    public function testIdsAreUnique(): void
    {
        $sf = $this->make(1);
        $seen = [];
        for ($i = 0; $i < 20000; $i++) {
            $id = $sf->id();
            self::assertArrayNotHasKey($id, $seen, '生成的 ID 出现重复');
            $seen[$id] = true;
        }
    }

    public function testIdsAreMonotonicallyIncreasing(): void
    {
        $sf = $this->make(2);
        $prev = 0;
        for ($i = 0; $i < 5000; $i++) {
            $id = $sf->id();
            self::assertGreaterThan($prev, $id, 'ID 未保持递增');
            $prev = $id;
        }
    }

    public function testIdIsPositive64BitInteger(): void
    {
        $sf = $this->make(3);
        $id = $sf->id();
        self::assertIsInt($id);
        self::assertGreaterThan(0, $id);
        // 符号位恒为 0 → 必须落在 63 位正整数范围内。
        self::assertLessThanOrEqual(0x7FFFFFFFFFFFFFFF, $id);
    }

    public function testParseRoundTrips(): void
    {
        $sf = $this->make(5);
        $id = $sf->id();
        $parts = $sf->parse($id);

        self::assertSame(5, $parts['worker_id']);
        self::assertGreaterThan(0, $parts['timestamp']);
        self::assertGreaterThanOrEqual(0, $parts['sequence']);
        self::assertLessThanOrEqual(4095, $parts['sequence']);
    }

    public function testDifferentNodesProduceDisjointIds(): void
    {
        $a = $this->make(0);
        $b = $this->make(1023);

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
        $this->expectException(ClusterException::class);
        new ClusterSnowflake(1024);
    }

    public function testLargeClockRollbackThrows(): void
    {
        $cluster = new ClusterSnowflake(1, 1704067200000);
        $cluster->next();

        // 把内部 lastTimestamp 拨到未来 10s，模拟大幅时钟回拨。
        $ref = new \ReflectionProperty(ClusterSnowflake::class, 'lastTimestamp');
        $ref->setAccessible(true);
        $ref->setValue($cluster, (int) (microtime(true) * 1000) + 10000);

        $this->expectException(ClusterException::class);
        $cluster->next();
    }

    public function testCustomEpochIsHonored(): void
    {
        $epoch = 1700000000000;
        $sf = $this->make(0, $epoch);
        $parts = $sf->parse($sf->id());

        // 解析出的绝对时间戳应 >= 自定义 epoch。
        self::assertGreaterThanOrEqual($epoch, $parts['timestamp']);
    }

    public function testResolveWorkerIdIsIsolatedPerProcess(): void
    {
        // 不同基址必得不同机器 ID（同进程内即可验证偏移逻辑）；
        // 范围恒落在 0~1023。
        $a = SnowflakeServiceProvider::resolveWorkerId(0);
        $b = SnowflakeServiceProvider::resolveWorkerId(1);
        self::assertNotSame($a, $b);
        foreach ([$a, $b, SnowflakeServiceProvider::resolveWorkerId(2000)] as $id) {
            self::assertGreaterThanOrEqual(0, $id);
            self::assertLessThanOrEqual(ClusterSnowflake::MAX_WORKER_ID, $id);
        }
    }
}
