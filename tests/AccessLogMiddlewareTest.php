<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Http\Middleware\AccessLogMiddleware;
use Kode\Framework\Http\Resp;
use Kode\Framework\Logging\AccessLogSink;
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

    public function testAsyncEmitsToSinkWithoutWritingLogger(): void
    {
        $sink = new AccessLogSink();
        // sink 注入且 async=true：热路径只入队，不写 logger
        $mw = new AccessLogMiddleware($this->logger, true, $sink, true);
        $mw->process(new ServerRequest('GET', '/api/users'), $this->okHandler(200));

        self::assertCount(0, $this->logger->records, '异步模式不应在请求路径内写 logger');
        self::assertSame(1, $sink->pending());

        // 离路径 flush：批量写入 logger 并清空队列
        $written = $sink->flush($this->logger);
        self::assertSame(1, $written);
        self::assertCount(1, $this->logger->records);
        self::assertSame('info', $this->logger->records[0]['level']);
        self::assertSame('/api/users', $this->logger->records[0]['context']['uri']);
        self::assertSame(0, $sink->pending());
    }

    public function testAsyncServerErrorLevelRecordedOnFlush(): void
    {
        $sink = new AccessLogSink();
        $mw = new AccessLogMiddleware($this->logger, true, $sink, true);
        $mw->process(new ServerRequest('POST', '/api/pay'), $this->okHandler(500));

        $sink->flush($this->logger);
        self::assertSame('error', $this->logger->records[0]['level']);
    }

    public function testSyncFallbackWhenSinkNull(): void
    {
        // 无 sink（如旧调用 / 未绑定容器）时强制同步写，保持向后兼容
        $mw = new AccessLogMiddleware($this->logger, true, null, true);
        $mw->process(new ServerRequest('GET', '/'), $this->okHandler(200));

        self::assertCount(1, $this->logger->records);
        self::assertSame('info', $this->logger->records[0]['level']);
    }

    public function testSyncFallbackWhenAsyncFalse(): void
    {
        $sink = new AccessLogSink();
        $mw = new AccessLogMiddleware($this->logger, true, $sink, false);
        $mw->process(new ServerRequest('GET', '/'), $this->okHandler(200));

        self::assertCount(1, $this->logger->records, 'async=false 应直接同步写 logger');
        self::assertSame(0, $sink->pending(), 'async=false 不应入队');
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
