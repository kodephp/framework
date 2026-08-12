<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Providers\ServiceProvider;
use Kode\Framework\Support\Snowflake;

/**
 * 分布式 ID（Snowflake）服务提供者。
 *
 * 依据 config/snowflake.php 构建 Snowflake 单例并绑定到容器，
 * 门面 Snowflake / 助手 snowflake() 复用它。
 */
final class SnowflakeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Snowflake::class, function (): Snowflake {
            return new Snowflake(
                workerId: (int) $this->config('snowflake.worker_id', 0),
                datacenterId: (int) $this->config('snowflake.datacenter_id', 0),
                epoch: (int) $this->config('snowflake.epoch', 1704067200000),
            );
        });

        $this->container->alias('snowflake', Snowflake::class);
    }
}
