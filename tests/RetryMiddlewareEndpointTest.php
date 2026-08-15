<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Http\Resp;
use Kode\Framework\Http\RouteRegistry;
use Kode\Http\App;
use Kode\Framework\Testing\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * HTTP 重试中间件集成测试（真引导框架，每条用例独立进程）。
 *
 * 验证全局 RetryMiddleware 已接线：安全方法（GET）下游返回 502/503/504 时，
 * 在同一次请求内自动重试，最终成功；而非安全方法（POST）默认不重试。
 */
final class RetryMiddlewareEndpointTest extends TestCase
{
    /** @var list<int> 记录 handler 每次被执行的时刻序号 */
    private array $runs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp();

        $runs = &$this->runs;
        resolve(App::class)->get('/retry-mw-test', static function () use (&$runs): mixed {
            $runs[] = count($runs) + 1;
            if (count($runs) < 2) {
                return Resp::error('upstream down', 503);
            }

            return Resp::json(['ok' => true, 'attempt' => count($runs)]);
        });

        resolve(App::class)->post('/retry-mw-test-post', static function () use (&$runs): mixed {
            $runs[] = count($runs) + 1;
            if (count($runs) < 2) {
                return Resp::error('upstream down', 503);
            }

            return Resp::json(['ok' => true]);
        });

        // 重试中间件已改为「按路由属性 #[Retry] 按需挂载」，需显式标记这些路由（模拟属性扫描登记）。
        $this->tagRetry('/retry-mw-test');
        $this->tagRetry('/retry-mw-test-post');
    }

    /**
     * 把已注册的路由标记为启用 HTTP 重试（模拟 #[Retry] 属性扫描登记）。
     */
    private function tagRetry(string $path, string $method = 'GET'): void
    {
        $app = resolve(App::class);
        $matched = $app->getRouter()->match($method, $path);
        if ($matched->isFound() && $matched->route !== null) {
            resolve(RouteRegistry::class)->tagRetry($matched->route, true);
        }
    }

    #[\RunInSeparateProcess]
    public function testRetriesUpstream5xxAndEventuallySucceeds(): void
    {
        $r = $this->get('/retry-mw-test')->assertStatus(200);

        self::assertTrue($r->json()['ok']);
        self::assertSame(2, count($this->runs), '重试应使 handler 在同一次请求内执行两次');
    }

    #[\RunInSeparateProcess]
    public function testPostMethodIsNotRetriedByDefault(): void
    {
        $r = $this->post('/retry-mw-test-post')->assertStatus(503);

        self::assertSame(1, count($this->runs), 'POST 默认不重试，首次 503 直接返回');
    }

    #[\RunInSeparateProcess]
    public function testUntaggedRouteBypassesRetry(): void
    {
        // 未标记 #[Retry] 的路由：重试中间件应 O(1) 早退，不包裹重试，
        // 下游 503 直接透传、handler 仅执行一次。
        $runs = &$this->runs;
        resolve(App::class)->get('/retry-mw-open', static function () use (&$runs): mixed {
            $runs[] = count($runs) + 1;

            return Resp::error('upstream down', 503);
        });

        $r = $this->get('/retry-mw-open')->assertStatus(503);
        self::assertSame(1, count($this->runs), '未标记路由不应被重试中间件包裹');
    }
}
