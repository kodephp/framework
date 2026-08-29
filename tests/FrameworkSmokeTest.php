<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Application;
use Kode\Framework\Idempotency\IdempotencyMiddleware;
use Kode\Framework\Http\Resp;
use Kode\Framework\Resilience\Breaker;
use Kode\Framework\Resilience\Retry;

/**
 * 框架整体功能门禁（端到端冒烟）。
 *
 * 启动一次真实应用，通过真实 HTTP 请求逐一验证核心子系统是否「真能用」：
 *  - 健康检查 / 存活探针
 *  - 全栈中间件管线（请求ID + 熔断可观测头）
 *  - 404 结构化 JSON
 *  - 路由 + DI 解析 + JSON 响应
 *  - 幂等中间件（Idempotency-Key：首次记录 / 重放）
 *  - 韧性 Provider 可解析（Retry / Breaker）
 *
 * 任一能力静默失接，本测试立即红灯——作为发布前的整体功能护栏。
 */
final class FrameworkSmokeTest extends TestCase
{
    private static bool $routesRegistered = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(__DIR__ . '/../');

        if (!self::$routesRegistered) {
            // DI + JSON 功能端点：解析一次单例 + 返回结构化响应。
            $this->app()->http()->get('/_smoke/json', function () {
                $this->app()->makeService(Retry::class); // 触发容器解析

                return Resp::json([
                    'ok'     => true,
                    'framework' => Application::VERSION,
                    'items'  => range(1, 10),
                ]);
            });

            // 幂等功能端点：显式标记 #[Idempotency]，供 test_idempotency_key_records_then_replays 验证
            // 「按路由属性按需挂载」后的幂等行为（健康探针 /ping 不携带该能力，属预期）。
            $this->app()->http()->get('/_smoke/idem', function () {
                return Resp::json(['ok' => true, 'nonce' => bin2hex(random_bytes(4))]);
            });
            $idemRoute = $this->app()->http()->getRouter()->match('GET', '/_smoke/idem');
            if ($idemRoute->isFound() && $idemRoute->route !== null) {
                resolve(\Kode\Framework\Http\RouteRegistry::class)
                    ->tagIdempotency($idemRoute->route, true);
                // 幂等中间件已改为「按路由属性 #[Idempotency] 按需挂载」，需显式挂到该路由。
                $idemRoute->route->middleware(resolve(IdempotencyMiddleware::class));
            }

            self::$routesRegistered = true;
        }
    }

    public function test_health_probe_exposes_version_and_php(): void
    {
        $this->get('/health')
            ->assertStatus(200)
            ->assertSee('"version"')
            ->assertSee(PHP_VERSION);
    }

    public function test_ping_returns_pong_through_full_middleware_stack(): void
    {
        $r = $this->get('/ping');

        $r->assertStatus(200);
        $this->assertSame('{"pong":true}', $r->body());

        // 证明全栈中间件管线已执行：请求ID（kode/http）+ 链路追踪头（本框架可观测）。
        // 注：熔断 / 重试 / 幂等中间件已改为「按路由属性 #[CircuitBreaker]/#[Retry]/#[Idempotency]
        // 按需挂载」——仅显式声明路由才纳入，健康探针 /ping 不携带这些头（属预期）。
        // 其按需挂载行为由 CircuitBreakerMiddlewareEndpointTest / RetryMiddlewareEndpointTest /
        // IdempotencyEndpointTest 专项验证。
        $this->assertNotEmpty($r->header('X-Request-Id'), '缺少 X-Request-Id：请求ID中间件未生效');
        $this->assertNotEmpty($r->header('X-Trace-Id'), '缺少 X-Trace-Id：链路追踪中间件未生效');
    }

    public function test_404_returns_structured_json(): void
    {
        $this->get('/__no_such_route__' . uniqid())
            ->assertStatus(404)
            ->assertSee('Not Found');
    }

    public function test_json_endpoint_resolves_di_and_serializes(): void
    {
        $r = $this->get('/_smoke/json');

        $r->assertStatus(200);
        $json = $r->json();
        $this->assertTrue($json['ok']);
        $this->assertSame(Application::VERSION, $json['framework']);
        $this->assertCount(10, $json['items']);
    }

    public function test_idempotency_key_records_then_replays(): void
    {
        $key = 'smoke-' . uniqid();

        $first = $this->get('/_smoke/idem', ['Idempotency-Key' => $key]);
        $first->assertStatus(200);
        $this->assertSame('true', $first->header('Idempotency-Recorded'), '首次请求应标记 Idempotency-Recorded');

        $second = $this->get('/_smoke/idem', ['Idempotency-Key' => $key]);
        $second->assertStatus(200);
        $this->assertSame('true', $second->header('Idempotency-Replay'), '相同幂等键应触发重放 Idempotency-Replay');
    }

    public function test_resilience_providers_are_resolvable(): void
    {
        $this->assertInstanceOf(Retry::class, $this->app()->makeService(Retry::class));
        $this->assertInstanceOf(Breaker::class, $this->app()->makeService(Breaker::class));
    }
}
