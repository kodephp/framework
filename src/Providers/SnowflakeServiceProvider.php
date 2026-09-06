<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Support\Snowflake;
use Kode\Process\Cluster\Snowflake as ClusterSnowflake;
use Kode\Framework\Providers\ServiceProvider;

/**
 * 分布式 ID（Snowflake）服务提供者。
 *
 * 算法由 kode/process 的 Cluster/Snowflake 提供；此处仅按 config/snowflake.php
 * 构造实例并绑定到容器，门面 Snowflake / 助手 snowflake() 复用它。
 */
final class SnowflakeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Snowflake::class, function (): Snowflake {
            $cluster = new ClusterSnowflake(
                workerId: self::resolveWorkerId((int) $this->config('snowflake.worker_id', 0)),
                epoch: (int) $this->config('snowflake.epoch', 1704067200000),
            );

            return new Snowflake($cluster);
        });

        $this->container->alias('snowflake', Snowflake::class);
    }

    /**
     * 多进程 worker 隔离的机器 ID。
     *
     * fork 后各 worker 若持相同机器 ID，同毫秒生成的 ID 必碰撞。本方法以配置值为
     * 基址叠加进程 PID 取模——Swoole / Workerman / Native 通吃，无需启动层透传
     * worker 序号（HttpServer 另有确定性重绑，见 HttpServer::bootWorker）。
     */
    public static function resolveWorkerId(int $base): int
    {
        $mod = ClusterSnowflake::MAX_WORKER_ID + 1;

        return ($base + getmypid()) % $mod;
    }
}
