<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Resilience\Breaker;
use Kode\Framework\Resilience\CircuitBreaker;
use Kode\Framework\Resilience\CircuitBreakerMiddleware;
use Kode\Framework\Resilience\Events\CircuitOpened;
use Kode\Http\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HTTP 熔断中间件单元测试。
 *
 * 用可完全控制的 FakeCircuitBreaker 隔离 kode/fibers，验证中间件自身的决策逻辑：
 * 健康透传、5xx 计入、OPEN 短路、排除路径、传输层异常、关闭、固定名、4xx 中性。
 */
final class CircuitBreakerMiddlewareTest extends TestCase
{
    private ?FakeCircuitBreaker $fake = null;

    /** @var array<int, object> */
    private array $dispatched = [];

    /**
     * 构造一个用可控 FakeCircuitBreaker 驱动的 Breaker 注册表。
     */
    private function fakeBreaker(string $name = 'svc'): Breaker
    {
        $this->fake = new FakeCircuitBreaker($name);

        return new Breaker([], fn (): CircuitBreaker => $this->fake);
    }

    private function handler(int $status, string $body = ''): RequestHandlerInterface
    {
        return new class($status, $body) implements RequestHandlerInterface {
            public function __construct(
                private readonly int $status,
                private readonly string $body,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::make($this->body, $this->status);
            }
        };
    }

    private function throwingHandler(\Throwable $e): RequestHandlerInterface
    {
        return new class($e) implements RequestHandlerInterface {
            public function __construct(private readonly \Throwable $e)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->e;
            }
        };
    }

    private function request(string $path = '/api/x', string $method = 'GET'): ServerRequestInterface
    {
        return new ServerRequest($method, $path);
    }

    public function testHealthyTwoHundredRecordsSuccessAndPassesThrough(): void
    {
        $mw = new CircuitBreakerMiddleware($this->fakeBreaker(), ['enabled' => true]);
        $resp = $mw->process($this->request(), $this->handler(200, 'ok'));

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('ok', (string) $resp->getBody());
        self::assertSame(['svc'], $this->fake->success);
        self::assertSame([], $this->fake->failure);
        self::assertSame('closed', $resp->getHeaderLine('X-Circuit-Breaker'));
        self::assertSame('/api/x', $resp->getHeaderLine('X-Circuit-Breaker-Name'));
    }

    public function testServerErrorRecordsFailureButPassesThrough(): void
    {
        $mw = new CircuitBreakerMiddleware($this->fakeBreaker(), ['enabled' => true]);
        $resp = $mw->process($this->request(), $this->handler(503, 'boom'));

        self::assertSame(503, $resp->getStatusCode());
        self::assertSame(['svc'], $this->fake->failure);
        self::assertSame([], $this->fake->success);
    }

    public function testOpenStateShortCircuitsWithoutInvokingHandler(): void
    {
        $breaker = $this->fakeBreaker();
        $this->fake->allow = false;
        $this->fake->state = 'open';
        $invoked = false;
        $handler = new class($invoked) implements RequestHandlerInterface {
            public function __construct(public bool &$invoked)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->invoked = true;

                return Response::make('', 200);
            }
        };

        $dispatcher = function (object $e): object {
            $this->dispatched[] = $e;

            return $e;
        };
        $mw = new CircuitBreakerMiddleware($breaker, ['enabled' => true, 'open_status' => 503], $dispatcher);
        $resp = $mw->process($this->request(), $handler);

        self::assertFalse($invoked, 'OPEN 时不应调用下游 handler');
        self::assertSame(503, $resp->getStatusCode());
        self::assertSame('open', $resp->getHeaderLine('X-Circuit-Breaker'));
        self::assertJsonStringEqualsJsonString(
            (string) json_encode(['code' => 503, 'msg' => 'circuit breaker open', 'data' => ['breaker' => '/api/x', 'state' => 'open']]),
            (string) $resp->getBody()
        );
        self::assertCount(1, $this->dispatched);
        self::assertInstanceOf(CircuitOpened::class, $this->dispatched[0]);
        self::assertSame('/api/x', $this->dispatched[0]->name);
    }

    public function testExcludedPathBypassesBreaker(): void
    {
        $mw = new CircuitBreakerMiddleware($this->fakeBreaker(), ['enabled' => true, 'exclude' => ['/health']]);
        $resp = $mw->process($this->request('/health/live'), $this->handler(200, 'alive'));

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame([], $this->fake->success);
        self::assertSame([], $this->fake->failure);
    }

    public function testTransportExceptionRecordsFailureAndRethrows(): void
    {
        $mw = new CircuitBreakerMiddleware($this->fakeBreaker(), ['enabled' => true]);
        $e = new \RuntimeException('connection refused');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('connection refused');
        try {
            $mw->process($this->request(), $this->throwingHandler($e));
        } finally {
            self::assertSame(['svc'], $this->fake->failure, '传输层异常应记为失败');
        }
    }

    public function testDisabledBypassesBreaker(): void
    {
        $mw = new CircuitBreakerMiddleware($this->fakeBreaker(), ['enabled' => false]);
        $resp = $mw->process($this->request(), $this->handler(500, 'ignored'));

        self::assertSame(500, $resp->getStatusCode());
        self::assertSame([], $this->fake->failure);
        self::assertSame([], $this->fake->success);
    }

    public function testFixedServiceNameUsedForBreakerKey(): void
    {
        $mw = new CircuitBreakerMiddleware(
            $this->fakeBreaker('fixed-svc'),
            ['enabled' => true, 'derive_from' => 'fixed', 'service_name' => 'fixed-svc']
        );
        $resp = $mw->process($this->request('/anything'), $this->handler(200));

        self::assertSame('fixed-svc', $resp->getHeaderLine('X-Circuit-Breaker-Name'));
        self::assertSame(['fixed-svc'], $this->fake->success);
    }

    public function testFourXXNeutralWhenRecord4xxFalse(): void
    {
        $mw = new CircuitBreakerMiddleware(
            $this->fakeBreaker(),
            ['enabled' => true, 'record_4xx_as_success' => false]
        );
        $resp = $mw->process($this->request(), $this->handler(404, 'not found'));

        self::assertSame(404, $resp->getStatusCode());
        self::assertSame([], $this->fake->success, 'record_4xx_as_success=false 时 4xx 中性');
        self::assertSame([], $this->fake->failure);
    }

    public function testFourXXCountsSuccessByDefault(): void
    {
        $mw = new CircuitBreakerMiddleware($this->fakeBreaker(), ['enabled' => true]);
        $resp = $mw->process($this->request(), $this->handler(400, 'bad request'));

        self::assertSame(400, $resp->getStatusCode());
        self::assertSame(['svc'], $this->fake->success, '默认 4xx 计为健康');
    }
}

/**
 * 可完全控制的熔断实现，隔离 kode/fibers 以验证中间件决策逻辑。
 *
 * @internal
 */
final class FakeCircuitBreaker implements CircuitBreaker
{
    /** @var array<int, string> */
    public array $success = [];
    /** @var array<int, string> */
    public array $failure = [];
    public bool $allow = true;
    public string $state = 'closed';

    public function __construct(private readonly string $name)
    {
    }

    public function allowRequest(): bool
    {
        return $this->allow;
    }

    public function recordSuccess(): void
    {
        $this->success[] = $this->name;
    }

    public function recordFailure(): void
    {
        $this->failure[] = $this->name;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function metrics(): array
    {
        return [];
    }
}
