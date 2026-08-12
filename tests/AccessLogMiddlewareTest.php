<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Http\Middleware\AccessLogMiddleware;
use Kode\Framework\Http\Resp;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 访问日志中间件测试：验证结构化日志包含 method/uri/status/latency_ms 等字段。
 */
final class AccessLogMiddlewareTest extends TestCase
{
    private ArrayLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new ArrayLogger();
    }

    private function okHandler(int $status = 200): RequestHandlerInterface
    {
        return new class ($status) implements RequestHandlerInterface {
            public function __construct(private int $status) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Resp::json('ok', $this->status);
            }
        };
    }

    public function testLogsSuccessWithMetrics(): void
    {
        $mw = new AccessLogMiddleware($this->logger, true);
        $request = new ServerRequest('GET', '/api/users');

        $response = $mw->process($request, $this->okHandler(200));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->logger->records);
        $rec = $this->logger->records[0];
        self::assertSame('access', $rec['message']);
        self::assertSame('GET', $rec['context']['method']);
        self::assertSame('/api/users', $rec['context']['uri']);
        self::assertSame(200, $rec['context']['status']);
        self::assertIsNumeric($rec['context']['latency_ms']);
        self::assertSame('info', $rec['level']);
    }

    public function testLogsErrorLevelForServerError(): void
    {
        $mw = new AccessLogMiddleware($this->logger, true);
        $request = new ServerRequest('POST', '/api/pay');

        $mw->process($request, $this->okHandler(500));

        self::assertSame('error', $this->logger->records[0]['level']);
    }

    public function testDisabledSkipsLogging(): void
    {
        $mw = new AccessLogMiddleware($this->logger, false);
        $mw->process(new ServerRequest('GET', '/'), $this->okHandler(200));

        self::assertCount(0, $this->logger->records);
    }

    public function testCapturesRequestIdFromHeader(): void
    {
        $mw = new AccessLogMiddleware($this->logger, true);
        $request = new ServerRequest('GET', '/', ['X-Request-Id' => 'req-123']);

        $mw->process($request, $this->okHandler(200));

        self::assertSame('req-123', $this->logger->records[0]['context']['request_id']);
    }
}

/**
 * 内存版 PSR-3 日志器，捕获级别/消息/上下文供断言。
 */
final class ArrayLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @var list<array{message: string, context: array, level: string}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
