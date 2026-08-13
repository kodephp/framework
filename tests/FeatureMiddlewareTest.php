<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Kode\Core\Config\Config;
use Kode\Framework\Feature\FeatureManager;
use Kode\Framework\Feature\FeatureRegistry;
use Kode\Framework\Feature\Middleware\FeatureMiddleware;
use Kode\Http\Routing\Router;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * FeatureMiddleware 集成验证：匹配路由查 flag，关闭时返回 fallback。
 */
final class FeatureMiddlewareTest extends TestCase
{
    private Router $router;
    private FeatureRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
        $this->registry = new FeatureRegistry();
    }

    private function manager(array $flags): FeatureManager
    {
        $config = new Config();
        $config->set('feature', ['enabled' => true, 'default' => false, 'flags' => $flags]);

        return new FeatureManager($config);
    }

    private function handler(?bool &$called): RequestHandlerInterface
    {
        return new class($called) implements RequestHandlerInterface {
            public function __construct(public mixed &$called)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;

                return new Response(200, [], 'ok');
            }
        };
    }

    private function request(string $path, ?string $userId = null): ServerRequestInterface
    {
        $req = new ServerRequest('GET', 'http://localhost' . $path);
        if ($userId !== null) {
            $req = $req->withHeader('X-User-Id', $userId);
        }

        return $req;
    }

    public function testUntaggedRoutePassesThrough(): void
    {
        // /open 在 registry 中没有任何 flag 登记 → 直接放行，不受 feature 开关影响。
        $this->router->add('GET', '/open', fn() => null);

        $mw = new FeatureMiddleware($this->router, $this->registry, $this->manager(['beta' => ['enabled' => true]]));

        $called = false;
        $resp = $mw->process($this->request('/open'), $this->handler($called));
        self::assertTrue($called);
        self::assertSame(200, $resp->getStatusCode());
    }

    public function testEnabledFlagInvokesHandler(): void
    {
        $route = $this->router->add('GET', '/beta', fn() => null);
        $this->registry->tag($route, 'beta-search', 404);

        $mw = new FeatureMiddleware($this->router, $this->registry, $this->manager(['beta-search' => ['enabled' => true]]));

        $called = false;
        $resp = $mw->process($this->request('/beta', 'user:1'), $this->handler($called));
        self::assertTrue($called);
        self::assertSame(200, $resp->getStatusCode());
    }

    public function testDisabledFlagReturnsFallback404(): void
    {
        $route = $this->router->add('GET', '/beta', fn() => null);
        $this->registry->tag($route, 'beta-search', 404);

        $mw = new FeatureMiddleware($this->router, $this->registry, $this->manager(['beta-search' => ['enabled' => false]]));

        $called = false;
        $resp = $mw->process($this->request('/beta', 'user:1'), $this->handler($called));
        self::assertFalse($called);
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testDisabledFlagWith403Fallback(): void
    {
        $route = $this->router->add('GET', '/beta', fn() => null);
        $this->registry->tag($route, 'beta-search', 403);

        $mw = new FeatureMiddleware($this->router, $this->registry, $this->manager(['beta-search' => ['enabled' => false]]));

        $resp = $mw->process($this->request('/beta', 'user:1'), $this->handler($ignored));
        self::assertSame(403, $resp->getStatusCode());
    }

    public function testGlobalDisabledPassesThrough(): void
    {
        $route = $this->router->add('GET', '/beta', fn() => null);
        $this->registry->tag($route, 'beta-search', 404);

        $config = new Config();
        $config->set('feature', ['enabled' => false]);
        $mw = new FeatureMiddleware($this->router, $this->registry, new FeatureManager($config), ['enabled' => false]);

        $called = false;
        $resp = $mw->process($this->request('/beta', 'user:1'), $this->handler($called));
        self::assertTrue($called);
        self::assertSame(200, $resp->getStatusCode());
    }

    public function testRolloutBucketsByUserHeader(): void
    {
        $route = $this->router->add('GET', '/beta', fn() => null);
        $this->registry->tag($route, 'beta-search', 404);

        $mw = new FeatureMiddleware(
            $this->router,
            $this->registry,
            $this->manager(['beta-search' => ['enabled' => true, 'rollout' => 50]]),
        );

        // 同一用户稳定命中/不命中
        $first = null;
        for ($i = 0; $i < 5; $i++) {
            $called = false;
            $resp = $mw->process($this->request('/beta', 'user:42'), $this->handler($called));
            $hit = $called && $resp->getStatusCode() === 200;
            if ($first === null) {
                $first = $hit;
            } else {
                self::assertSame($first, $hit, '同一用户分桶应稳定');
            }
        }
    }
}
