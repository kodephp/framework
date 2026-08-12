<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Http\Middleware\CorsMiddleware;
use Kode\Http\Middleware\RequestId;
use Kode\Http\Middleware\SecurityHeaders;
use Kode\Http\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 企业级全局中间件单元测试：请求追踪 / CORS / 安全头。
 *
 * 这些中间件实现已委托 kode/http 原生类，框架只做配置映射（见
 * HttpServiceProvider::corsConfig / securityHeadersConfig）。本测试直接对
 * kode/http 原生中间件做断言，验证委托后的行为符合预期。
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
        $mw = new RequestId(header: 'X-Request-Id');
        $resp = $mw->process(new ServerRequest('GET', '/'), $this->okHandler());

        $id = $resp->getHeaderLine('X-Request-Id');
        // kode/http RequestId 生成 24 位十六进制（native 行为）。
        self::assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $id);
    }

    public function testRequestIdAllowsClientOverride(): void
    {
        $mw = new RequestId(header: 'X-Request-Id');
        $req = (new ServerRequest('GET', '/'))->withHeader('X-Request-Id', 'client-trace-1');
        $resp = $mw->process($req, $this->okHandler());

        self::assertSame('client-trace-1', $resp->getHeaderLine('X-Request-Id'));
    }

    public function testCorsPreflightReturns204(): void
    {
        $mw = new CorsMiddleware([
            'origin' => '*',
            'methods' => ['GET', 'POST'],
            'headers' => ['Content-Type'],
            'max_age' => 86400,
            'credentials' => false,
        ]);
        $req = (new ServerRequest('OPTIONS', '/'))->withHeader('Origin', 'https://a.com');
        $resp = $mw->process($req, $this->okHandler());

        self::assertSame(204, $resp->getStatusCode());
        self::assertNotEmpty($resp->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('GET, POST', $resp->getHeaderLine('Access-Control-Allow-Methods'));
    }

    public function testCorsAppendsHeadersToNormalResponse(): void
    {
        $mw = new CorsMiddleware([
            'origin' => ['https://a.com'],
            'methods' => ['GET', 'POST'],
            'headers' => ['Content-Type'],
            'max_age' => 86400,
            'credentials' => false,
        ]);
        $req = (new ServerRequest('GET', '/'))->withHeader('Origin', 'https://a.com');
        $resp = $mw->process($req, $this->okHandler());

        self::assertSame('https://a.com', $resp->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testSecurityHeadersApplied(): void
    {
        $mw = new SecurityHeaders(
            headers: [
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'DENY',
                'Strict-Transport-Security' => 'max-age=31536000',
            ],
            hsts: false,
        );
        $resp = $mw->process(new ServerRequest('GET', '/'), $this->okHandler());

        self::assertSame('nosniff', $resp->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $resp->getHeaderLine('X-Frame-Options'));
        self::assertSame('max-age=31536000', $resp->getHeaderLine('Strict-Transport-Security'));
    }
}
