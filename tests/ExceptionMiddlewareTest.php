<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Exception\ExceptionManager;
use Kode\Exception\Formatter\UnifiedResponseFormatter;
use Kode\Framework\Application;
use Kode\Framework\Http\Middleware\ExceptionMiddleware;
use Kode\Framework\Validation\ValidationException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * ExceptionMiddleware 单元测试：验证错误响应走 kode/exception，
 * 默认结构化 JSON（开发环境含 file / line / chain 追踪到源文件位置），无 HTML 调试页。
 */
final class ExceptionMiddlewareTest extends TestCase
{
    private function manager(bool $production = false): ExceptionManager
    {
        return new ExceptionManager(
            logger: new \Psr\Log\NullLogger(),
            formatter: new UnifiedResponseFormatter($production),
            isProduction: $production,
        );
    }

    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return \Kode\Http\Response::make('ok', 200);
            }
        };
    }

    public function testGenericExceptionReturnsJsonWithLocationInDebug(): void
    {
        $mw = new ExceptionMiddleware($this->manager(false));

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('boom at runtime');
            }
        };

        $resp = $mw->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(500, $resp->getStatusCode());
        self::assertStringContainsString('application/json', $resp->getHeaderLine('Content-Type'));

        $body = json_decode((string) $resp->getBody(), true);
        self::assertSame('E9999', $body['code']);
        self::assertSame('boom at runtime', $body['msg']);
        // 可追踪到出错文件与行号（API 框架核心诉求）。
        self::assertArrayHasKey('location', $body);
        self::assertArrayHasKey('file', $body['location']);
        self::assertArrayHasKey('line', $body['location']);
        self::assertArrayHasKey('chain', $body);
    }

    public function testGenericExceptionHidesDetailsInProduction(): void
    {
        $mw = new ExceptionMiddleware($this->manager(true));

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('secret internal path');
            }
        };

        $body = json_decode((string) $mw->process(new ServerRequest('GET', '/'), $handler)->getBody(), true);
        self::assertSame('系统繁忙，请稍后重试', $body['msg']);
        self::assertArrayNotHasKey('location', $body);
    }

    public function testValidationExceptionReturns422WithErrors(): void
    {
        $mw = new ExceptionMiddleware($this->manager(false));

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new ValidationException([['field' => 'email', 'message' => '必填']]);
            }
        };

        $resp = $mw->process(new ServerRequest('GET', '/'), $handler);
        self::assertSame(422, $resp->getStatusCode());

        $body = json_decode((string) $resp->getBody(), true);
        self::assertSame('参数校验失败', $body['message']);
        self::assertSame([['field' => 'email', 'message' => '必填']], $body['errors']);
    }

    public function testHappyPathPassesThrough(): void
    {
        $mw = new ExceptionMiddleware($this->manager(false));
        $resp = $mw->process(new ServerRequest('GET', '/'), $this->okHandler());
        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('ok', (string) $resp->getBody());
    }

    /**
     * 守护 L-2 修复：启动真实应用后，框架异常中间件必须以 prepend 方式成为
     * 全局管线的最外层（替代 kode/http 默认的 JsonErrorHandlerMiddleware），
     * 且整个过程不再依赖反射改写 App 的私有 dispatcher 属性。
     */
    public function testExceptionMiddlewareIsOutermostInRealApp(): void
    {
        Application::make(dirname(__DIR__));

        /** @var \Kode\Http\App $app */
        $app = resolve(\Kode\Http\App::class);
        $middlewares = $app->getDispatcher()->getMiddlewares();

        self::assertNotEmpty($middlewares);
        self::assertInstanceOf(ExceptionMiddleware::class, $middlewares[0]);
    }
}
