<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Http\Resp;
use Kode\Framework\Http\RouteRegistry;
use Kode\Http\App;
use Kode\Framework\Testing\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * 幂等中间件集成测试（真引导框架，每条用例独立进程）。
 *
 * 验证全局 IdempotencyMiddleware 已接线：携带 Idempotency-Key 的请求，
 * 首跑返回 Idempotency-Recorded: true，重放返回首次缓存响应体 + Idempotency-Replay: true，
 * 且下游 handler 仅执行一次；缺头请求直接放行。
 */
final class IdempotencyEndpointTest extends TestCase
{
    /** @var array<int, float> 记录 handler 每次被执行的时刻 */
    private array $runs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp();

        $runs = &$this->runs;
        resolve(App::class)->get('/idem-test', static function () use (&$runs): mixed {
            $runs[] = microtime(true);

            return Resp::json(['runs' => count($runs), 'nonce' => bin2hex(random_bytes(4))]);
        });

        // 幂等中间件已改为「按路由属性 #[Idempotency] 按需挂载」，需显式标记该路由（模拟属性扫描登记）。
        $app = resolve(App::class);
        $matched = $app->getRouter()->match('GET', '/idem-test');
        if ($matched->isFound() && $matched->route !== null) {
            resolve(RouteRegistry::class)->tagIdempotency($matched->route, true);
        }
    }

    #[\RunInSeparateProcess]
    public function testReplayReturnsCachedBodyAndHandlerRunsOnce(): void
    {
        $first = $this->get('/idem-test', ['Idempotency-Key' => 'abc'])->assertStatus(200);
        $second = $this->get('/idem-test', ['Idempotency-Key' => 'abc'])->assertStatus(200);

        self::assertSame($first->body(), $second->body(), '重放应返回首次缓存响应体');
        self::assertSame('true', $first->header('Idempotency-Recorded'));
        self::assertSame('true', $second->header('Idempotency-Replay'));
        self::assertSame(1, count($this->runs), '下游 handler 只应执行一次');
    }

    #[\RunInSeparateProcess]
    public function testDifferentKeyExecutesHandlerAgain(): void
    {
        $this->get('/idem-test', ['Idempotency-Key' => 'k1'])->assertStatus(200);
        $this->get('/idem-test', ['Idempotency-Key' => 'k2'])->assertStatus(200);

        self::assertSame(2, count($this->runs), '不同键应视为不同请求');
    }

    #[\RunInSeparateProcess]
    public function testMissingHeaderPassesThrough(): void
    {
        $r = $this->get('/idem-test')->assertStatus(200);

        self::assertSame('', $r->header('Idempotency-Replay'));
        self::assertSame('', $r->header('Idempotency-Recorded'));
        self::assertSame(1, count($this->runs));
    }

    #[\RunInSeparateProcess]
    public function testUntaggedRouteBypassesIdempotency(): void
    {
        // 未标记 #[Idempotency] 的路由：幂等中间件应 O(1) 早退，不查存储、不去重，
        // 即便携带相同 Idempotency-Key，handler 仍每次执行、不返回重放头。
        $runs = &$this->runs;
        resolve(App::class)->get('/idem-open', static function () use (&$runs): mixed {
            $runs[] = microtime(true);

            return Resp::json(['runs' => count($runs), 'nonce' => bin2hex(random_bytes(4))]);
        });

        $first = $this->get('/idem-open', ['Idempotency-Key' => 'same-key'])->assertStatus(200);
        $second = $this->get('/idem-open', ['Idempotency-Key' => 'same-key'])->assertStatus(200);

        self::assertSame(2, count($this->runs), '未标记路由 handler 每次都执行（不去重）');
        self::assertSame('', $first->header('Idempotency-Recorded'));
        self::assertSame('', $second->header('Idempotency-Replay'));
        self::assertNotSame($first->body(), $second->body(), '未标记路由每次返回新响应（非重放）');
    }
}
