<?php

declare(strict_types=1);

namespace Kode\Framework\Facades;

use Kode\Core\Facade;

/**
 * 分布式 ID 门面：Snowflake::id() / Snowflake::parse($id)。
 *
 * 底层服务为 Kode\Framework\Support\Snowflake，由 SnowflakeServiceProvider 绑定，
 * 配置见 config/snowflake.php（worker_id / datacenter_id / epoch）。
 */
final class Snowflake extends Facade
{
    protected static function id(): string
    {
        return \Kode\Framework\Support\Snowflake::class;
    }
}
