<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Kode\Framework\Application;
use Kode\Framework\Tenant\HeaderTenantResolver;
use Kode\Framework\Tenant\SubdomainTenantResolver;
use Kode\Framework\Tenant\TenantContext;
use Kode\Framework\Tenant\TenantMiddleware;
use Kode\Framework\Tenant\TenantResolver;
use Kode\Framework\Tests\Fixtures\Tenant\FixtureTenantResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 多租户上下文原语（生产级增强）验证：
 *  - 内置解析器（header / subdomain）与自定义 TenantResolver 行为正确；
 *  - TenantMiddleware 把租户写入「每请求隔离 scope」，下游 tenant() 可读、请求后自动出栈不串扰；
 *  - TenantServiceProvider 正确绑定 TenantMiddleware（默认无解析器）。
 */
final class TenantProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (app() === null) {
            Application::make(dirname(__DIR__));
        }
    }

    /**
     * 在 scope 内捕获 tenant() 的 handler（可变引用）。
     */
    private function captureHandler(?string &$out): RequestHandlerInterface
    {
        return new class($out) implements RequestHandlerInterface {
            public function __construct(public mixed &$out)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->out = tenant();

                return new Response(200);
            }
        };
    }

    public function testHeaderResolver(): void
    {
        $r = new HeaderTenantResolver('X-Tenant-Id');
        $req = new ServerRequest('GET', '/', ['X-Tenant-Id' => 'acme']);

        self::assertSame('acme', $r->resolve($req));
        self::assertNull($r->resolve(new ServerRequest('GET', '/')));
    }

    public function testSubdomainResolver(): void
    {
        $r = new SubdomainTenantResolver('example.com');
        self::assertSame('acme', $r->resolve(new ServerRequest('GET', 'http://acme.example.com/')));
        // 无子域（仅基础域名）→ null
        self::assertNull($r->resolve(new ServerRequest('GET', 'http://example.com/')));
    }

    public function testCustomResolverImplementsInterface(): void
    {
        $r = new FixtureTenantResolver();
        self::assertInstanceOf(TenantResolver::class, $r);
        self::assertSame('shop', $r->resolve(new ServerRequest('GET', '/?tenant=shop')));
    }

    public function testMiddlewareResolvesAndScopesTenant(): void
    {
        $inside = null;
        $mw = new TenantMiddleware(new HeaderTenantResolver('X-Tenant-Id'), null);
        $req = new ServerRequest('GET', '/', ['X-Tenant-Id' => 'acme']);

        $mw->process($req, $this->captureHandler($inside));

        // scope 内（handler 执行期间）可读到
        self::assertSame('acme', $inside);
        // scope 出栈后：请求外 tenant() 恒为 null（绝不跨请求串扰）
        self::assertNull(tenant());
        self::assertFalse(TenantContext::has());
    }

    public function testMiddlewareFallsBackToDefaultWhenUnresolved(): void
    {
        $inside = null;
        $mw = new TenantMiddleware(new HeaderTenantResolver('X-Tenant-Id'), 'global');
        // 无 X-Tenant-Id 头 → 回退 default
        $mw->process(new ServerRequest('GET', '/'), $this->captureHandler($inside));

        self::assertSame('global', $inside);
    }

    public function testMiddlewareSetsRequestAttribute(): void
    {
        $seenAttr = null;
        $handler = new class($seenAttr) implements RequestHandlerInterface {
            public function __construct(public mixed &$out)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->out = $request->getAttribute('tenant');

                return new Response(200);
            }
        };

        $mw = new TenantMiddleware(new FixtureTenantResolver(), null);
        $mw->process(new ServerRequest('GET', '/?tenant=shop'), $handler);

        self::assertSame('shop', $seenAttr);
    }

    public function testProviderBindsMiddlewareAndNullResolverByDefault(): void
    {
        /** @var TenantMiddleware $mw */
        $mw = app()->container->get(TenantMiddleware::class);
        self::assertInstanceOf(TenantMiddleware::class, $mw);

        // 默认 config('tenant.resolver') 为 null → 绑定 null 解析器（仅用 default 回退）
        self::assertNull(app()->container->get(TenantResolver::class));
    }

    public function testHelperReturnsNullOutsideRequest(): void
    {
        // 未经过 TenantMiddleware：CLI 上下文恒为 null
        self::assertNull(tenant());
    }
}
