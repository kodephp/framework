<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Application;
use Kode\Framework\Http\Middleware\RateLimitMiddleware;
use Kode\Framework\Http\RateLimit\LimiterFactory;
use Kode\Framework\Http\RateLimit\RateLimitAttributeReader;
use Kode\Framework\Http\RouteRegistry;
use Kode\Framework\Tests\Fixtures\Controllers\RateLimitedController;
use Kode\Http\Routing\Router;
use Kode\Limiting\Attribute\RateLimit;
use Kode\Limiting\Enum\LimiterType;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 限流单元测试：
 *  - RateLimitAttributeReader 读取类级 + 方法级 #[RateLimit]（IS_REPEATABLE 叠加）。
 *  - LimiterFactory 按「规则 + 统一存储配置」合成 Limiter，并复用缓存。
 *  - RateLimitMiddleware：有规则走细粒度（超限 429 + 标准头），无规则走全局默认。
 */
final class RateLimitTest extends TestCase
{
    private RateLimitAttributeReader $reader;

    protected function setUp(): void
    {
        $this->reader = new RateLimitAttributeReader();
    }

    public function testReadsClassAndMethodLevelRules(): void
    {
        $rules = $this->reader->read(RateLimitedController::class, 'index');

        // 类级 1 条 + 方法级 1 条。
        self::assertCount(2, $rules);
        self::assertSame(50, $rules[0]->capacity);   // 类级
        self::assertSame(3, $rules[1]->capacity);    // 方法级（index）
        self::assertSame('fixture:list:{ip}', $rules[1]->key);
    }

    public function testReadsOnlyClassLevelWhenMethodHasNoAttribute(): void
    {
        $rules = $this->reader->read(RateLimitedController::class, 'show');

        self::assertCount(1, $rules);
        self::assertSame(50, $rules[0]->capacity);
    }

    public function testLimiterFactoryBuildsAndCaches(): void
    {
        $config = ['driver' => 'memory', 'capacity' => 10, 'rate' => 1.0, 'algorithm' => 'token_bucket'];
        $factory = new LimiterFactory($config);

        $rule = new RateLimit(capacity: 10, rate: 1.0);
        $a = $factory->make($rule);
        $b = $factory->make($rule);

        // 同签名应复用同一实例（缓存连接）。
        self::assertSame($a, $b);

        $result = $a->consume('k:' . uniqid(), 1);
        self::assertTrue($result->isAllowed());
    }

    public function testMiddlewareAppliesAttributeRuleAndReturns429(): void
    {
        $router = new Router();
        $registry = new RouteRegistry();
        $factory = new LimiterFactory(['driver' => 'memory', 'capacity' => 10, 'rate' => 1.0]);

        // 受限路径：容量 2，单次消耗 1。
        $rules = [new RateLimit(capacity: 2, rate: 1.0, type: LimiterType::TOKEN_BUCKET)];
        $route = $router->add('GET', '/limited', fn() => new Response(200));
        $registry->tagRateLimits($route, $rules);

        $mw = new RateLimitMiddleware($router, $registry, $factory, ['enabled' => true, 'driver' => 'memory', 'capacity' => 10]);

        $handler = new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'ok');
            }
        };

        $ok = 0;
        $denied = 0;
        for ($i = 0; $i < 5; $i++) {
            $req = new ServerRequest('GET', '/limited');
            $resp = $mw->process($req, $handler);
            if ($resp->getStatusCode() === 429) {
                $denied++;
                self::assertNotEmpty($resp->getHeaderLine('Retry-After'));
                self::assertNotEmpty($resp->getHeaderLine('X-RateLimit-Limit'));
            } else {
                $ok++;
            }
        }

        // 容量 2 → 前 2 通，后 3 拒绝。
        self::assertSame(2, $ok);
        self::assertSame(3, $denied);
    }

    public function testMiddlewareFallsBackToGlobalWhenNoRule(): void
    {
        $router = new Router();
        $registry = new RouteRegistry();
        // 全局默认容量 1，便于触发。
        $factory = new LimiterFactory(['driver' => 'memory', 'capacity' => 1, 'rate' => 0.1, 'algorithm' => 'token_bucket']);

        // 该路由无任何 #[RateLimit] 规则。
        $router->add('GET', '/open', fn() => new Response(200));

        $mw = new RateLimitMiddleware($router, $registry, $factory, ['enabled' => true, 'driver' => 'memory', 'capacity' => 1, 'rate' => 0.1]);

        $handler = new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'ok');
            }
        };

        $first = $mw->process(new ServerRequest('GET', '/open'), $handler);
        self::assertSame(200, $first->getStatusCode());

        $second = $mw->process(new ServerRequest('GET', '/open'), $handler);
        self::assertSame(429, $second->getStatusCode());
    }

    public function testDisabledConfigBypassesLimiting(): void
    {
        $router = new Router();
        $registry = new RouteRegistry();
        $factory = new LimiterFactory(['driver' => 'memory', 'capacity' => 1, 'rate' => 0.1]);
        $router->add('GET', '/x', fn() => new Response(200));

        $mw = new RateLimitMiddleware($router, $registry, $factory, ['enabled' => false]);
        $handler = new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        for ($i = 0; $i < 3; $i++) {
            self::assertSame(200, $mw->process(new ServerRequest('GET', '/x'), $handler)->getStatusCode());
        }
    }

    public function testAttributeRoutesAreTaggedInRealApp(): void
    {
        // 启动真实应用，验证 ProductsController 的 #[RateLimit] 已被登记到 registry。
        Application::make(dirname(__DIR__));
        /** @var RouteRegistry $registry */
        $registry = resolve(RouteRegistry::class);

        $tagged = 0;
        foreach (resolve(\Kode\Http\App::class)->getRouter()->getRoutes() as $route) {
            if ($registry->rateLimitsOf($route) !== []) {
                $tagged++;
            }
        }

        // ProductsController 有 4 条属性路由，均继承了类级 #[RateLimit]。
        self::assertGreaterThanOrEqual(4, $tagged);
    }
}
