<?php

declare(strict_types=1);

namespace Kode\Framework\Support;

/**
 * 分布式 ID 生成器（Twitter Snowflake 算法）。
 *
 * 64 位整数结构（符号位恒为 0）：
 *   ┌─ 41 位时间戳（毫秒，相对自定义 epoch）
 *   ├─ 5 位数据中心 id
 *   ├─ 5 位机器（worker）id
 *   └─ 12 位序列号（同一毫秒内自增，支持 4096/s/节点）
 *
 * 特性：
 *   - 趋势递增、全局近似有序，适合做数据库主键 / 全局唯一标识。
 *   - 多实例部署时由 datacenter_id + worker_id 区分节点，避免碰撞。
 *   - 时钟回拨保护：轻微回拨自旋等待追上；大幅回拨（>5s）抛异常。
 *
 * 用法：
 *   snowflake()->id();                       // 门面 / 助手（推荐）
 *   new Snowflake($workerId, $datacenterId); // 也可直接实例化
 */
final class Snowflake
{
    /**
     * 默认纪元：2024-01-01T00:00:00Z（毫秒）。可用更近的纪元延长可用年限。
     */
    private const DEFAULT_EPOCH = 1704067200000;

    private const TIMESTAMP_BITS = 41;
    private const DATACENTER_BITS = 5;
    private const WORKER_BITS = 5;
    private const SEQUENCE_BITS = 12;

    private const MAX_DATACENTER = (1 << self::DATACENTER_BITS) - 1; // 31
    private const MAX_WORKER = (1 << self::WORKER_BITS) - 1;          // 31
    private const MAX_SEQUENCE = (1 << self::SEQUENCE_BITS) - 1;     // 4095

    private const CLOCK_ROLLBACK_LIMIT_MS = 5000;

    private int $epoch;
    private int $datacenterId;
    private int $workerId;

    private int $lastTimestamp = -1;
    private int $sequence = 0;

    public function __construct(int $workerId = 0, int $datacenterId = 0, ?int $epoch = null)
    {
        if ($datacenterId < 0 || $datacenterId > self::MAX_DATACENTER) {
            throw new \InvalidArgumentException(
                sprintf('datacenter_id 必须在 0-%d 之间，收到 %d', self::MAX_DATACENTER, $datacenterId)
            );
        }
        if ($workerId < 0 || $workerId > self::MAX_WORKER) {
            throw new \InvalidArgumentException(
                sprintf('worker_id 必须在 0-%d 之间，收到 %d', self::MAX_WORKER, $workerId)
            );
        }

        $this->epoch = $epoch ?? self::DEFAULT_EPOCH;
        $this->datacenterId = $datacenterId;
        $this->workerId = $workerId;
    }

    /**
     * 生成下一个全局唯一 ID（int）。
     *
     * @throws \RuntimeException 当系统时钟大幅回拨超过阈值时
     */
    public function id(): int
    {
        $timestamp = $this->currentMillis();

        if ($timestamp < $this->lastTimestamp) {
            $diff = $this->lastTimestamp - $timestamp;
            if ($diff > self::CLOCK_ROLLBACK_LIMIT_MS) {
                throw new \RuntimeException(
                    sprintf('时钟回拨过大（%dms），拒绝生成 ID', $diff)
                );
            }
            // 轻微回拨：自旋等待追上上一时间戳。
            while ($timestamp < $this->lastTimestamp) {
                $timestamp = $this->currentMillis();
                usleep(1000);
            }
        }

        if ($timestamp === $this->lastTimestamp) {
            $this->sequence = ($this->sequence + 1) & self::MAX_SEQUENCE;
            if ($this->sequence === 0) {
                // 当前毫秒序列耗尽：自旋到下一毫秒。
                while ($timestamp <= $this->lastTimestamp) {
                    $timestamp = $this->currentMillis();
                }
            }
        } else {
            $this->sequence = 0;
        }

        $this->lastTimestamp = $timestamp;

        return $this->pack($timestamp, $this->sequence);
    }

    /**
     * 反解 ID 为组成字段（便于排查 / 审计）。
     *
     * @return array{timestamp:int,datacenter_id:int,worker_id:int,sequence:int}
     */
    public function parse(int $id): array
    {
        $sequence = $id & self::MAX_SEQUENCE;
        $workerId = ($id >> self::SEQUENCE_BITS) & self::MAX_WORKER;
        $datacenterId = ($id >> (self::SEQUENCE_BITS + self::WORKER_BITS)) & self::MAX_DATACENTER;
        $timestamp = ($id >> (self::SEQUENCE_BITS + self::WORKER_BITS + self::DATACENTER_BITS)) + $this->epoch;

        return [
            'timestamp' => $timestamp,
            'datacenter_id' => $datacenterId,
            'worker_id' => $workerId,
            'sequence' => $sequence,
        ];
    }

    private function pack(int $timestamp, int $sequence): int
    {
        $delta = $timestamp - $this->epoch;

        return (($delta << (self::DATACENTER_BITS + self::WORKER_BITS + self::SEQUENCE_BITS))
            | ($this->datacenterId << (self::WORKER_BITS + self::SEQUENCE_BITS))
            | ($this->workerId << self::SEQUENCE_BITS)
            | $sequence);
    }

    private function currentMillis(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
