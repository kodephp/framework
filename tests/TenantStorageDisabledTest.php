<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Database\Db\Db;
use Kode\Framework\Tenant\Storage\StaticTenantStorageResolver;
use Kode\Framework\Tenant\Storage\TenantStorageManager;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * 多租户存储隔离「关闭态 / 不隔离」测试。
 *
 *  - 未引导框架时，tenant_storage() 助手返回 null（null 安全）。
 *  - shared 策略的 manager 不切换任何连接（boot 返回 null、currentConnection 为 null）。
 */
final class TenantStorageDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Db::setDefaultConnection('mysql');
    }

    #[RunInSeparateProcess]
    public function testHelperReturnsNullWhenNotBooted(): void
    {
        self::assertNull(tenant_storage());
    }

    #[RunInSeparateProcess]
    public function testSharedStrategyProducesNoSwitch(): void
    {
        $resolver = new StaticTenantStorageResolver('shared', 'mysql', ['database' => 'forge'], 'mysql', 'tnt_', [], 'fallback');
        $manager = new TenantStorageManager($resolver, 'mysql', fn (object $e): object => $e, []);

        self::assertNull($manager->connectionName('acme'));
        self::assertNull($manager->boot('acme'));
        self::assertSame('mysql', Db::getDefaultConnection());
        self::assertNull($manager->currentConnection());
    }
}
