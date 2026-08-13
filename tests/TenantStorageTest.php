<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Database\Db\Db;
use Kode\Framework\Tenant\Storage\StaticTenantStorageResolver;
use Kode\Framework\Tenant\Storage\TenantConnectionResolver;
use Kode\Framework\Tenant\Storage\TenantStorageManager;
use Kode\Framework\Tenant\Storage\TenantStorageMiddleware;
use Kode\Framework\Tenant\Storage\TenantStorageUnresolved;
use Kode\Framework\Tenant\TenantContext;
use Kode\Context\Context;
use Kode\Exception\KodeException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 多租户存储隔离单元测试（直接构造解析器 / 管理器，不引导框架）。
 *
 * 覆盖：shared/database/schema/map 策略、on_missing=abort、sanitize、boot/restore、
 * currentConnection、中间件把未解析租户转为 404。
 */
final class TenantStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Db::setDefaultConnection('mysql');
    }

    protected function tearDown(): void
    {
        Db::setDefaultConnection('mysql');
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function template(): array
    {
        return [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'forge',
            'username' => 'forge',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ];
    }

    private function manager(string $strategy, array $map = [], string $onMissing = 'fallback'): TenantStorageManager
    {
        $resolver = new StaticTenantStorageResolver($strategy, 'mysql', $this->template(), 'mysql', 'tnt_', $map, $onMissing);

        return new TenantStorageManager($resolver, 'mysql', fn (object $e): object => $e, []);
    }

    #[RunInSeparateProcess]
    public function testSharedStrategyDoesNotSwitch(): void
    {
        $m = $this->manager('shared');

        self::assertNull($m->connectionName('acme'));
        self::assertNull($m->boot('acme'));
        self::assertSame('mysql', Db::getDefaultConnection());
        self::assertNull($m->currentConnection());
    }

    #[RunInSeparateProcess]
    public function testDatabaseStrategySwitchesAndRestores(): void
    {
        $m = $this->manager('database');

        $previous = $m->boot('acme');
        self::assertSame('mysql', $previous);
        self::assertSame('tenant_acme', Db::getDefaultConnection());
        self::assertSame('tenant_acme', $m->currentConnection());
        self::assertSame('tenant_acme', $m->connectionName('acme'));

        $m->restore($previous);
        self::assertSame('mysql', Db::getDefaultConnection());
        self::assertNull($m->currentConnection());
    }

    #[RunInSeparateProcess]
    public function testSchemaStrategyAlsoDerivesDatabaseName(): void
    {
        $r = new StaticTenantStorageResolver('schema', 'mysql', $this->template(), 'mysql', 'tnt_', [], 'fallback');
        $cfg = $r->resolve('acme');

        self::assertNotNull($cfg);
        self::assertSame('tnt_acme', $cfg['database']);
    }

    #[RunInSeparateProcess]
    public function testDatabaseNamingSanitizesTenantId(): void
    {
        $r = new StaticTenantStorageResolver('database', 'mysql', $this->template(), 'mysql', 'tnt_', [], 'fallback');

        self::assertSame('tnt_acme_foo', $r->resolve('acme.foo')['database']);
        self::assertSame('tnt_acme_123', $r->resolve('acme-123')['database']);
    }

    #[RunInSeparateProcess]
    public function testMapStrategyReusesRegisteredConnection(): void
    {
        Db::addConnection('altconn', $this->template());
        $m = $this->manager('map', ['acme' => 'altconn']);

        self::assertSame('altconn', $m->connectionName('acme'));
        $previous = $m->boot('acme');
        self::assertSame('altconn', Db::getDefaultConnection());
        $m->restore($previous);
        self::assertSame('mysql', Db::getDefaultConnection());
    }

    #[RunInSeparateProcess]
    public function testMapStrategyMergesOverride(): void
    {
        $m = $this->manager('map', ['acme' => ['database' => 'custom_db']]);

        self::assertSame('tenant_acme', $m->connectionName('acme'));
        $previous = $m->boot('acme');
        self::assertSame('tenant_acme', Db::getDefaultConnection());
        $m->restore($previous);
    }

    #[RunInSeparateProcess]
    public function testOnMissingAbortThrows(): void
    {
        $r = new StaticTenantStorageResolver('map', 'mysql', $this->template(), 'mysql', 'tnt_', [], 'abort');

        $this->expectException(TenantStorageUnresolved::class);
        $r->resolve('unknown');
    }

    #[RunInSeparateProcess]
    public function testMiddlewareConvertsUnresolvedTenantToNotFound(): void
    {
        $m = $this->manager('map', [], 'abort');
        $middleware = new TenantStorageMiddleware($m);

        $request = (new ServerRequest('GET', '/'))->withHeader('X-Tenant-Id', 'ghost');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        $this->expectException(KodeException::class);

        Context::runWith([TenantContext::KEY => 'ghost'], function () use ($middleware, $request, $handler): void {
            $middleware->process($request, $handler);
        });
    }

    #[RunInSeparateProcess]
    public function testMiddlewareNoOpWhenNoTenant(): void
    {
        $m = $this->manager('database');
        $middleware = new TenantStorageMiddleware($m);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('mysql', Db::getDefaultConnection());
        self::assertNull($m->currentConnection());
    }

    public function testCustomResolverInterfaceExists(): void
    {
        self::assertTrue(is_a(StaticTenantStorageResolver::class, TenantConnectionResolver::class, true));
    }
}
