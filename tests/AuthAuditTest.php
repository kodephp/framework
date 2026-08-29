<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Context\Context;
use Kode\Framework\Contracts\AuthGuard;
use Kode\Framework\Http\Middleware\AuthMiddleware;
use Kode\Framework\Security\Audit\AuditSink;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Kode\Http\Request;
use Kode\Http\Response;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 鉴权安全审计事件测试。
 *
 * 验证 AuthMiddleware 在鉴别成功 / 失败时，经离路径异步审计管线记录
 * auth.success / auth.failed（不阻塞主流程）；且成功事件传入显式 userId 后
 * 不清除 kode/context 的 auth_user_id，避免抢占请求审计的用户标识。
 */
final class AuthAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp();
        AuditSink::reset();
    }

    private function fakeGuard(bool $valid): object
    {
        return new class($valid) {
            public function __construct(private bool $valid)
            {
            }

            public function authenticate(string $token, string $guard = 'api'): object
            {
                if (!$this->valid) {
                    throw new \RuntimeException('invalid token');
                }

                return new class('user-42') {
                    public function __construct(private string $uid)
                    {
                    }

                    public function getSubject(): ?string
                    {
                        return $this->uid;
                    }

                    public function getUserIdentifier(): string
                    {
                        return $this->uid;
                    }
                };
            }
        };
    }

    #[RunInSeparateProcess]
    public function testAuthFailureEmitsAuditEvent(): void
    {
        $this->bindFakeGuard(false);

        $mw = new AuthMiddleware();
        $req = (new ServerRequest('GET', '/'))->withHeader('Authorization', 'Bearer bad');
        Request::setRequest($req);

        $response = $mw->process($req, $this->passHandler());
        self::assertSame(401, $response->getStatusCode());

        $events = $this->flushEvents();
        self::assertContains('auth.failed', array_column($events, 'event'));
    }

    #[RunInSeparateProcess]
    public function testAuthSuccessEmitsAuditEventAndKeepsContext(): void
    {
        $this->bindFakeGuard(true);

        $mw = new AuthMiddleware();
        $req = (new ServerRequest('GET', '/'))->withHeader('Authorization', 'Bearer good');
        Request::setRequest($req);

        $response = $mw->process($req, $this->passHandler());
        self::assertSame(200, $response->getStatusCode());

        $events = $this->flushEvents();
        self::assertContains('auth.success', array_column($events, 'event'));

        // 显式 userId 不应清除 context，供请求审计继续记录用户。
        self::assertSame('user-42', Context::get('auth_user_id'));
    }

    /**
     * 用假守卫覆盖容器中的真实 JwtGuard（单例缓存于具体类名键下，需 forget + instance 具体类）。
     */
    private function bindFakeGuard(bool $valid): void
    {
        $concrete = get_class(resolve(AuthGuard::class));
        app()->container->forget($concrete);
        app()->container->instance($concrete, $this->fakeGuard($valid));
    }

    private function passHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::make('', 200);
            }
        };
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

        // Monolog 3 的 TestHandler 记录为 LogRecord 对象（非数组）。
        return array_map(static fn(\Monolog\LogRecord $r): array => $r->context, $handler->getRecords());
    }
}
