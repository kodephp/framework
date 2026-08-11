<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Http\Middleware\CorsMiddleware;
use Kode\Framework\Http\Middleware\RequestIdMiddleware;
use Kode\Framework\Http\Middleware\SecurityHeadersMiddleware;
use Kode\Http\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 企业级全局中间件单元测试：请求追踪 / CORS / 安全头。
 */
final class MiddlewareTest extends TestCase
{
    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::make('', 200);
            }
        };
    }

    public function testRequestIdIsGeneratedAndReturned(): void
    {
        $mw = new RequestIdMiddleware(['enabled' => true, 'request_id_allow_client' => false]);
        $resp = $mw->process(new ServerRequest('GET', '/'), $this->okHandler());

        $id = $resp->getHeaderLine('X-Request-Id');
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
    }

    public function testRequestIdAllowsClientOverride(): void
    {
        $mw = new RequestIdMiddleware(['enabled' => true, 'request_id_allow_client' => true]);
        $req = (new ServerRequest('GET', '/'))->withHeader('X-Request-Id', 'client-trace-1');
        $resp = $mw->process($req, $this->okHandler());

        self::assertSame('client-trace-1', $resp->getHeaderLine('X-Request-Id'));
    }

    public function testCorsPreflightReturns204(): void
    {
        $mw = new CorsMiddleware([
            'enabled' => true,
            'allowed_origins' => '*',
            'allowed_methods' => ['GET', 'POST'],
            'allowed_headers' => ['Content-Type'],
            'max_age' => 86400,
            'allow_credentials' => false,
        ]);
        $req = (new ServerRequest('OPTIONS', '/'))->withHeader('Origin', 'https://a.com');
        $resp = $mw->process($req, $this->okHandler());

        self::assertSame(204, $resp->getStatusCode());
        self::assertSame('*', $resp->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testCorsAppendsHeadersToNormalResponse(): void
    {
        $mw = new CorsMiddleware([
            'enabled' => true,
            'allowed_origins' => 'https://a.com',
            'allowed_methods' => ['GET', 'POST'],
            'allowed_headers' => ['Content-Type'],
            'max_age' => 86400,
            'allow_credentials' => false,
        ]);
        $req = (new ServerRequest('GET', '/'))->withHeader('Origin', 'https://a.com');
        $resp = $mw->process($req, $this->okHandler());

        self::assertSame('https://a.com', $resp->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testSecurityHeadersApplied(): void
    {
        $mw = new SecurityHeadersMiddleware([
            'enabled' => true,
            'nosniff' => true,
            'frame_options' => 'DENY',
            'referrer_policy' => 'strict-origin-when-cross-origin',
            'hsts' => 'max-age=31536000',
        ]);
        $resp = $mw->process(new ServerRequest('GET', '/'), $this->okHandler());

        self::assertSame('nosniff', $resp->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $resp->getHeaderLine('X-Frame-Options'));
        self::assertSame('max-age=31536000', $resp->getHeaderLine('Strict-Transport-Security'));
    }
}
