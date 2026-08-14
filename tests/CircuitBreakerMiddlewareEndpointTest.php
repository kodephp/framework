<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Http\Resp;
use Kode\Http\App;
use Kode\Framework\Testing\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * HTTP 熔断中间件集成测试（真引导框架，每条用例独立进程）。
 *
 * 验证全局 CircuitBreakerMiddleware 已接线：下游持续 5xx 时，失败累积至阈值后
 * 熔断器打开，后续请求在边缘短路返回 503（连重试都不发起），handler 不再执行。
 *
 * 每条用例使用互不相同的路由，避免共享 breaker 状态相互影响。
 */
final class CircuitBreakerMiddlewareEndpointTest extends TestCase
{
    private int $runs = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp();

        $runs = &$this->runs;
        resolve(App::class)->get('/cb-mw-trip', static function () use (&$runs): mixed {
            $runs++;

            return Resp::error('boom', 500);
        });

        resolve(App::class)->get('/cb-mw-healthy', static function (): mixed {
            return Resp::json(['ok' => true]);
        });

        // 独立探针路由：用于「健康不误熔断」用例，避免与上面的 trip 路由共享 breaker。
        resolve(App::class)->get('/cb-mw-probe', static function (): mixed {
            return Resp::error('boom', 500);
        });
    }

    #[\RunInSeparateProcess]
    public function testRepeated5xxOpensBreakerAndShortCircuits(): void
    {
        // 默认 failure_threshold = 5：前 4 次 500 累积失败（状态 closed）；
        // 第 5 次记录第 5 次失败时达阈值，熔断转为 open（本次响应头部即 open，但 handler 仍执行）。
        for ($i = 1; $i <= 4; $i++) {
            $r = $this->get('/cb-mw-trip');
            $r->assertStatus(500);
            self::assertSame('closed', $r->header('X-Circuit-Breaker'));
            self::assertSame('boom', $r->json()['message'] ?? null);
        }

        $r = $this->get('/cb-mw-trip');
        $r->assertStatus(500);
        self::assertSame(5, $this->runs, '达阈值前 handler 应已执行 5 次');

        // 第 6 次：熔断已打开，中间件短路，handler 不再执行。
        $r = $this->get('/cb-mw-trip');
        $r->assertStatus(503);
        self::assertSame('open', $r->header('X-Circuit-Breaker'));
        self::assertSame('circuit breaker open', $r->json()['msg'] ?? null);
        self::assertSame(5, $this->runs, '短路后 handler 不应再执行');
    }

    #[\RunInSeparateProcess]
    public function testHealthyResponsesDoNotTripBreaker(): void
    {
        // 对独立路由 /cb-mw-healthy 连发 4 次 200：breaker 保持 closed（2xx 重置失败计数）。
        for ($i = 1; $i <= 4; $i++) {
            $r = $this->get('/cb-mw-healthy');
            $r->assertStatus(200);
            self::assertSame('closed', $r->header('X-Circuit-Breaker'));
        }

        // 用独立探针路由发一次 500：因失败计数清零，熔断未打开，正常穿透返回 500。
        $r = $this->get('/cb-mw-probe');
        $r->assertStatus(500);
        self::assertSame('boom', $r->json()['message'] ?? null);
    }
}
