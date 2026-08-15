<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Http\RouteRegistry;
use Kode\Framework\Security\Audit\AuditSink;
use Kode\Framework\Testing\TestCase;
use Kode\Http\App;
use Kode\Http\Response;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * CSRF 防护集成测试（数组会话驱动，进程内持久化，无需落盘）。
 *
 * 验证「按需挂载」立场：
 *  - 被 #[Csrf] 标记的路由：GET 引导 X-CSRF-Token、POST 缺令牌 419、带令牌 200；
 *  - 未被标记的路由：CsrfMiddleware 早退、不阻断（证明企业中间件不影响无关路由响应）。
 */
final class CsrfMiddlewareTest extends TestCase
{
    private static bool $routes = false;

    protected function setUp(): void
    {
        parent::setUp();
        // 用内存驱动，避免临时文件；必须在 bootApp 读配置前生效。
        putenv('SESSION_DRIVER=array');
        $_ENV['SESSION_DRIVER'] = 'array';
        $this->bootApp();

        if (!self::$routes) {
            /** @var App $app */
            $app = resolve(App::class);
            /** @var RouteRegistry $registry */
            $registry = resolve(RouteRegistry::class);

            $registry->tagCsrf($app->get('/csrf/form', static fn() => Response::make('', 200)), true);
            $registry->tagCsrf($app->post('/csrf/action', static fn() => Response::make('', 200)), true);
            // 未标记路由：应完全不受 CSRF 影响。
            $app->post('/csrf/open', static fn() => Response::make('', 200));
            // 模拟「登录后轮换令牌」的真实调用点：在请求处理内调用 csrf_token_rotate()，
            // 此时会话已由 LazySessionMiddleware 绑定，助手可正确定位会话。
            $app->get('/csrf/rotate', static fn() => Response::make((string) (csrf_token_rotate() ?? ''), 200));

            self::$routes = true;
        }
    }

    #[RunInSeparateProcess]
    public function testGetBootstrapsTokenHeaderAndStartsSession(): void
    {
        $resp = $this->get('/csrf/form');
        $resp->assertStatus(200);

        // 安全方法必须引导令牌回传。
        self::assertNotSame('', $resp->header('X-CSRF-Token'), 'GET 应回传 X-CSRF-Token 引导令牌');
        // 且确实启用了会话（令牌需存于会话）。
        self::assertNotSame('', $resp->header('Set-Cookie'), 'CSRF 路由应启用会话');
    }

    #[RunInSeparateProcess]
    public function testPostWithoutTokenIsRejected(): void
    {
        $resp = $this->post('/csrf/action', []);
        $resp->assertStatus(419);
    }

    #[RunInSeparateProcess]
    public function testPostWithValidTokenIsAccepted(): void
    {
        $boot = $this->get('/csrf/form');
        $token = $boot->header('X-CSRF-Token');
        $sessionId = $this->sessionId($boot->header('Set-Cookie'));

        self::assertNotSame('', $token);
        self::assertNotNull($sessionId, 'GET 响应应下发会话 Cookie');

        // 携带会话 Cookie（Nyholm 不解析 Cookie 头，须用 cookie params）+ 令牌头重放 POST。
        /** @var App $app */
        $app = resolve(App::class);
        $req = (new ServerRequest('POST', '/csrf/action'))
            ->withHeader('X-CSRF-Token', $token)
            ->withCookieParams(['KODE_SESSION' => $sessionId]);
        $resp = $app->handle($req);

        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());
    }

    #[RunInSeparateProcess]
    public function testUntaggedRouteIsNotBlocked(): void
    {
        // 未被标记的 POST 路由：CsrfMiddleware 早退，正常 200。
        $resp = $this->post('/csrf/open', []);
        $resp->assertStatus(200);
    }

    #[RunInSeparateProcess]
    public function testPostWithoutTokenEmitsCsrfFailedAuditEvent(): void
    {
        AuditSink::reset();

        $resp = $this->post('/csrf/action', []);
        $resp->assertStatus(419);

        // 校验失败应经离路径异步审计管线记录 csrf.failed 安全事件。
        $events = $this->flushEvents();
        $csrfEvents = array_filter($events, static fn(array $e): bool => ($e['event'] ?? null) === 'csrf.failed');
        self::assertNotEmpty($csrfEvents, 'CSRF 失败应记录 csrf.failed 审计事件');

        $event = array_values($csrfEvents)[0];
        self::assertSame('missing_token', $event['detail']['reason'] ?? null);
        self::assertSame('/csrf/action', $event['detail']['path'] ?? null);
    }

    #[RunInSeparateProcess]
    public function testTokenRotationInvalidatesPreviousToken(): void
    {
        $boot = $this->get('/csrf/form');
        $token = $boot->header('X-CSRF-Token');
        $sessionId = $this->sessionId($boot->header('Set-Cookie'));
        self::assertNotSame('', $token);
        self::assertNotNull($sessionId);

        // 在请求处理内调用 csrf_token_rotate()（模拟登录后轮换），使助手能定位已绑定会话。
        $rotateReq = (new ServerRequest('GET', '/csrf/rotate'))
            ->withCookieParams(['KODE_SESSION' => $sessionId]);
        /** @var App $app */
        $app = resolve(App::class);
        $newToken = $app->handle($rotateReq)->getBody()->__toString();
        self::assertNotSame($token, $newToken, '轮换后应得到新令牌');

        // 用旧令牌 POST：应 419（旧令牌作废）。
        $oldReq = (new ServerRequest('POST', '/csrf/action'))
            ->withHeader('X-CSRF-Token', $token)
            ->withCookieParams(['KODE_SESSION' => $sessionId]);
        self::assertSame(419, $app->handle($oldReq)->getStatusCode(), '旧令牌应在轮换后失效');

        // 用新令牌 POST：应 200。
        $newReq = (new ServerRequest('POST', '/csrf/action'))
            ->withHeader('X-CSRF-Token', $newToken)
            ->withCookieParams(['KODE_SESSION' => $sessionId]);
        self::assertSame(200, $app->handle($newReq)->getStatusCode(), '新令牌应可用');
    }

    /**
     * 把离路径审计队列 flush 进内存 logger，返回各条记录 context（含 event 字段）。
     *
     * @return list<array<string, mixed>>
     */
    private function flushEvents(): array
    {
        /** @var AuditSink $sink */
        $sink = resolve(AuditSink::class);
        $handler = new TestHandler();
        $sink->flush(new Logger('test', [$handler]));

        return array_map(static fn(\Monolog\LogRecord $r): array => $r->context, $handler->getRecords());
    }

    private function sessionId(string $setCookie): ?string
    {
        // Set-Cookie: KODE_SESSION=<id>; path=/; ...
        if (preg_match('/KODE_SESSION=([^;]+)/', $setCookie, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
