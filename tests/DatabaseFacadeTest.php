<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Database\Db\Db;
use Kode\Framework\Application;
use PHPUnit\Framework\TestCase;

/**
 * 数据库门面冒烟测试。
 *
 * 验证 DB 门面 / db() 助手能正常解析并转发到 kode/database 的 Db 静态代理
 * （Db 是静态代理类，门面以实例方式代理其静态方法）。全程不建立真实数据库连接。
 *
 * 注意：全局助手 db() 是大小写不敏感的，会同时占用 DB 这个名字，故本测试直接用
 * 完全限定名 \Kode\Framework\Facades\DB 引用门面，避免 use 导入与该全局函数冲突。
 */
final class DatabaseFacadeTest extends TestCase
{
    public function testFacadeResolvesAndForwards(): void
    {
        Application::make(dirname(__DIR__));

        // 门面背后实例是 Db 静态代理类。
        self::assertInstanceOf(Db::class, \Kode\Framework\Facades\DB::getInstance());

        // db() 助手与门面解析到同一实例。
        self::assertInstanceOf(Db::class, db());

        // 转发到 Db::getConfig() 静态方法应返回数组（证明实例→静态桥接生效）。
        $config = \Kode\Framework\Facades\DB::getConfig();
        self::assertIsArray($config);
        self::assertNotEmpty($config);
    }
}
