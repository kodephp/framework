<?php

declare(strict_types=1);

namespace Kode\Framework\Support;

use Kode\Process\Cluster\Snowflake as ClusterSnowflake;

/**
 * 分布式 ID（Snowflake）薄适配。
 *
 * 算法实现完全委托 kode/process 的 {@see ClusterSnowflake}（fork 安全、含时钟回拨保护、
 * ID 解析与机器 ID 重绑）。本类只做命名对齐：框架习惯用 id()/parse()，包用 next()/parse()，
 * 不对算法做任何重复实现。
 *
 *   snowflake()->id();        // 生成下一个全局唯一 64 位 ID
 *   snowflake()->parse($id);  // 反解：时间 / 机器 / 序列
 */
final class Snowflake
{
    public function __construct(private readonly ClusterSnowflake $inner)
    {
    }

    /**
     * 生成下一个 ID（委托 ClusterSnowflake::next()）。
     */
    public function id(): int
    {
        return $this->inner->next();
    }

    /**
     * 同 {@see id()}；与包 API 对齐的别名。
     */
    public function next(): int
    {
        return $this->inner->next();
    }

    /**
     * 16 位十六进制形式（适合 URL / traceId）。
     */
    public function nextHex(): string
    {
        return $this->inner->nextHex();
    }

    /**
     * 反解 ID（委托 ClusterSnowflake::parse()）。
     *
     * @return array{id: int, timestamp: int, datetime: string, worker_id: int, sequence: int}
     */
    public function parse(int $id): array
    {
        return ClusterSnowflake::parse($id, $this->inner->epoch());
    }

    public function workerId(): int
    {
        return $this->inner->workerId();
    }

    public function epoch(): int
    {
        return $this->inner->epoch();
    }
}
