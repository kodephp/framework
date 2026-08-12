<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Context\Context;
use Kode\Framework\Http\Middleware\VersioningMiddleware;
use Kode\Framework\Security\Audit\AuditService;
use Kode\Framework\Testing\TestCase;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * 安全与合规：审计服务 + API 版本化 + 安全响应头（CSP 等）。
 */
final class SecurityComplianceTest extends TestCase
{
    // ------------------------------------------------------------------
    // 审计服务单元
    // ------------------------------------------------------------------

    private function makeLogger(array &$log): LoggerInterface
    {
        return new class($log) implements LoggerInterface {
            use LoggerTrait;
            public function __construct(private array &$log) {}
            public function log($level, $message, array $context = []): void
            {
                $this->log[] = ['level' => $level, 'message' => $message, 'context' => $context];
            }
        };
    }

    public function testAuditSkipsIgnorePaths(): void
    {
        $log = [];
        $svc = new AuditService($this->makeLogger($log), ['ignore_paths' => ['/health']]);
        $svc->record(new ServerRequest('GET', '/health'), new Response(200), microtime(true));
        self::assertSame([], $log);
    }

    public function testAuditRecordsContextAndUser(): void
    {
        $log = [];
        Context::set('auth_user_id', 'u-42');
        $svc = new AuditService($this->makeLogger($log), ['ignore_paths' => [], 'capture_user' => true]);
        $svc->record(
            new ServerRequest('POST', '/api/orders', ['X-Request-Id' => 'r1']),
            new Response(201),
            microtime(true)
        );

        self::assertCount(1, $log);
        $ctx = $log[0]['context'];
        self::assertSame('POST', $ctx['method']);
        self::assertSame('/api/orders', $ctx['path']);
        self::assertSame(201, $ctx['status']);
        self::assertSame('u-42', $ctx['user_id']);
        // 读取后清除，避免跨请求泄漏。
        self::assertNull(Context::get('auth_user_id'));
    }

    // ------------------------------------------------------------------
    // 版本化中间件单元
    // ------------------------------------------------------------------

    private function okHandler(): \Psr\Http\Server\RequestHandlerInterface
    {
        return new class() implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): Response
            {
                return new Response(200, [], 'ok');
            }
        };
    }

    public function testVersioningRequiresPrefixWhenEnabled(): void
    {
        $mw = new VersioningMiddleware(['enabled' => true, 'prefix_required' => true, 'supported_versions' => ['v1']]);
        $resp = $mw->process(new ServerRequest('GET', '/users'), $this->okHandler());
        self::assertSame(400, $resp->getStatusCode());
    }

    public function testVersioningRejectsUnsupported(): void
    {
        $mw = new VersioningMiddleware(['enabled' => true, 'prefix_required' => true, 'supported_versions' => ['v1']]);
        $resp = $mw->process(new ServerRequest('GET', '/v2/users'), $this->okHandler());
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testVersioningAllowsSupportedAndSetsAttribute(): void
    {
        $mw = new VersioningMiddleware(['enabled' => true, 'prefix_required' => true, 'supported_versions' => ['v1']]);
        $captured = null;
        $handler = new class() implements \Psr\Http\Server\RequestHandlerInterface {
            public ?string $version = null;
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): Response
            {
                $this->version = $request->getAttribute('api_version');
                return new Response(200);
            }
        };
        $resp = $mw->process(new ServerRequest('GET', '/v1/users'), $handler);
        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('v1', $handler->version);
    }

    public function testVersioningSkipsInfra(): void
    {
        $mw = new VersioningMiddleware(['enabled' => true, 'prefix_required' => true, 'supported_versions' => ['v1']]);
        $resp = $mw->process(new ServerRequest('GET', '/health'), $this->okHandler());
        self::assertSame(200, $resp->getStatusCode());
    }

    // ------------------------------------------------------------------
    // 安全响应头集成
    // ------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(getcwd());
    }

    public function testSecurityHeadersIncludeCspAndCoop(): void
    {
        $res = $this->get('/health');
        self::assertNotEmpty($res->header('Content-Security-Policy'));
        self::assertSame('same-origin', $res->header('Cross-Origin-Opener-Policy'));
        self::assertSame('same-origin', $res->header('Cross-Origin-Resource-Policy'));
    }
}
