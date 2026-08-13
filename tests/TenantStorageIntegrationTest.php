<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Database\Db\Db;
use Kode\Http\App;
use Kode\Framework\Tenant\Storage\TenantStorageManager;
use Kode\Framework\Testing\TestCase;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Http\Message\ResponseInterface;

/**
 * 多租户存储隔离集成测试（真实引导框架，phpunit.xml 已注入 tenant+storage 固定配置）。
 *
 * 请求带 X-Tenant-Id 时应在请求级切换到 tenant_acme 连接、响应后恢复默认连接；
 * 不带租户头时零开销放行。每方法独立进程（避免 Db 静态状态跨测试污染）。
 */
final class TenantStorageIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp();
    }

    #[RunInSeparateProcess]
    public function testRequestWithTenantHeaderSwitchesAndRestoresConnection(): void
    {
        $request = (new ServerRequest('GET', '/'))->withHeader('X-Tenant-Id', 'acme');

        /** @var App $http */
        $http = resolve(App::class);
        $response = $http->handle($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
        // 请求结束后必须恢复默认连接（绝不跨请求串扰）。
        self::assertSame('mysql', Db::getDefaultConnection());
        // 租户连接已注册到 kode/database（dry-run 解析即命中缓存）。
        self::assertSame('tenant_acme', tenant_storage()?->connectionName('acme'));
        self::assertNull(tenant_storage()?->currentConnection());
    }

    #[RunInSeparateProcess]
    public function testRequestWithoutTenantHeaderDoesNotSwitch(): void
    {
        $request = new ServerRequest('GET', '/');

        /** @var App $http */
        $http = resolve(App::class);
        $response = $http->handle($request);

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame('mysql', Db::getDefaultConnection());
        self::assertNull(tenant_storage()?->currentConnection());
    }

    #[RunInSeparateProcess]
    public function testTenantStorageManagerIsBound(): void
    {
        self::assertInstanceOf(TenantStorageManager::class, tenant_storage());
    }
}
