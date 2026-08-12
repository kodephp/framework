<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Application;
use Kode\Framework\Database\DatabaseProxy;
use Kode\Framework\Facades\DB;
use PHPUnit\Framework\TestCase;

/**
 * 数据库门面回归测试。
 *
 * 守护 L-1 修复：kode/database 的 Db 是静态代理类，门面背后必须是真正的实例
 * （DatabaseProxy），由它以「实例调用 → 转发 Db 静态方法」桥接，避免以实例语法
 * 调用静态方法触发弃用告警（PHP 8.1+）并为 PHP 9 预留兼容。
 *
 * 全程不建立真实数据库连接（仅调用 getConfig / 实例判断），可在任意环境跑。
 */
final class DatabaseFacadeTest extends TestCase
{
    /**
     * 门面 id 必须指向 DatabaseProxy，而非直接指向静态代理类 Db。
     */
    public function testFacadeIdPointsToProxy(): void
    {
        self::assertSame(DatabaseProxy::class, DB::getServiceId());
    }

    /**
     * 启动真实应用后，门面与 db() 助手都应解析出 DatabaseProxy 实例，
     * 且静态方法的转发（getConfig）能正常返回配置数组。
     */
    public function testFacadeResolvesProxyAndForwards(): void
    {
        Application::make(dirname(__DIR__));

        // 门面背后实例是代理，而非无意义的 new Db()。
        self::assertInstanceOf(DatabaseProxy::class, DB::getInstance());

        // db() 助手与门面指向同一代理实例。
        self::assertInstanceOf(DatabaseProxy::class, db());

        // 转发到 Db::getConfig() 静态方法应返回数组（证明实例→静态桥接生效）。
        $config = DB::getConfig();
        self::assertIsArray($config);
        self::assertNotEmpty($config);
    }
}
