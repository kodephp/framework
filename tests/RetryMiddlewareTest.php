<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Resilience\Backoff\FixedBackoff;
use Kode\Framework\Resilience\Retry;
use Kode\Framework\Resilience\RetryableHttpStatusException;
use Kode\Framework\Resilience\RetryExhausted;
use Kode\Framework\Resilience\RetryMiddleware;
use Kode\Http\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/**
 * 自定义「可重试」异常（模拟应用层上游瞬态异常，如 UpstreamUnavailableException）。
 */
final class UpstreamDownException extends \RuntimeException
{
}

/**
 * 计数型 handler：用闭包驱动，闭包内直接读写测试用例的 $runs 属性以统计真实执行次数。
 */
final class CountingHandler implements RequestHandlerInterface
{
    /** @param callable(\Psr\Http\Message\ServerRequestInterface):ResponseInterface $fn */
    public function __construct(private $fn)
    {
    }

    public function handle(\Psr\Http\Message\ServerRequestInterface $request): ResponseInterface
    {
        return ($this->fn)($request);
    }
}

/**
 * HTTP 重试中间件单元测试（直接驱动中间件 + 内置 Retry，不启动整框架）。
 *
 * 验证：
 *  - 非安全方法（POST）默认不重试，直接透传；
 *  - 安全方法遇 503 → 重试 → 200，handler 执行两次、退避一次；
 *  - 持续 5xx 耗尽 → 返回最后一次上游响应（best-effort），不伪造成功；
 *  - 非重试集内异常（RuntimeException）→ 还原原始异常透传；
 *  - 配置了 retry_on_exception → 该异常被重试；
 *  - 关闭（enabled=false）→ 直接透传；
 *  - 状态码不在 retry_on_status（如 500）→ 不重试；
 *  - attempts 上限被尊重。
 */
final class RetryMiddlewareTest extends TestCase
{
    /** @var list<float> 记录每次退避等待的秒数 */
    private array $slept = [];

    /** @var list<int> 记录 handler 每次被执行的序号 */
    private array $runs = [];

    private function retry(?FixedBackoff $backoff = null): Retry
    {
        return new Retry($backoff, null, function (float $s): void {
            $this->slept[] = $s;
        });
    }

    /**
     * 按给定响应序列响应：依次返回，序列耗尽后回退 200。
     *
     * @param ResponseInterface ...$responses
     */
    private function respondSequence(ResponseInterface ...$responses): RequestHandlerInterface
    {
        $queue = $responses;

        return new CountingHandler(function () use (&$queue): ResponseInterface {
            $this->runs[] = count($this->runs) + 1;
            $r = array_shift($queue);

            return $r ?? Response::make('', 200);
        });
    }

    private function throwOnFirstThen(ResponseInterface $ok): RequestHandlerInterface
    {
        return new CountingHandler(function () use ($ok): ResponseInterface {
            $this->runs[] = count($this->runs) + 1;
            if (count($this->runs) === 1) {
                throw new UpstreamDownException('temp down');
            }

            return $ok;
        });
    }

    private function alwaysThrow(\Throwable $e): RequestHandlerInterface
    {
        return new CountingHandler(function () use ($e): ResponseInterface {
            $this->runs[] = count($this->runs) + 1;
            throw $e;
        });
    }

    private function mw(Retry $retry, array $options = []): RetryMiddleware
    {
        return new RetryMiddleware($retry, $options);
    }

    private function request(string $method = 'GET'): ServerRequest
    {
        return new ServerRequest($method, '/api/resource');
    }

    public function testPassThroughForNonRetryablePostMethod(): void
    {
        $r = $this->mw($this->retry())->process(
            $this->request('POST'),
            $this->respondSequence(Response::make('', 503))
        );

        self::assertSame(503, $r->getStatusCode());
        self::assertSame([1], $this->runs, 'POST 不应重试');
        self::assertSame([], $this->slept);
    }

    public function testRetriesOn503ThenReturns200(): void
    {
        $r = $this->mw($this->retry(new FixedBackoff(0.01)))->process(
            $this->request(),
            $this->respondSequence(Response::make('', 503), Response::make('', 200))
        );

        self::assertSame(200, $r->getStatusCode());
        self::assertSame([1, 2], $this->runs, '应重试一次');
        self::assertSame([0.01], $this->slept);
    }

    public function testExhaustsAndReturnsLast5xx(): void
    {
        $r = $this->mw($this->retry(new FixedBackoff(0.01)), ['attempts' => 3])->process(
            $this->request(),
            $this->respondSequence(Response::make('', 503), Response::make('', 503), Response::make('', 503))
        );

        self::assertSame(503, $r->getStatusCode(), '耗尽后返回最后一次上游响应');
        self::assertSame([1, 2, 3], $this->runs);
        self::assertCount(2, $this->slept);
    }

    public function testNonRetryableExceptionPropagatesOriginal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $this->mw($this->retry())->process(
            $this->request(),
            $this->alwaysThrow(new RuntimeException('boom'))
        );
    }

    public function testConfiguredExceptionIsRetried(): void
    {
        $r = $this->mw($this->retry(new FixedBackoff(0.01)), [
            'retry_on_exception' => [UpstreamDownException::class],
        ])->process(
            $this->request(),
            $this->throwOnFirstThen(Response::make('', 200))
        );

        self::assertSame(200, $r->getStatusCode());
        self::assertSame([1, 2], $this->runs, '配置异常应被重试');
    }

    public function testDisabledPassesThrough(): void
    {
        $r = $this->mw($this->retry(), ['enabled' => false])->process(
            $this->request(),
            $this->respondSequence(Response::make('', 503))
        );

        self::assertSame(503, $r->getStatusCode());
        self::assertSame([1], $this->runs);
        self::assertSame([], $this->slept);
    }

    public function testStatusNotInListNotRetried(): void
    {
        $r = $this->mw($this->retry())->process(
            $this->request(),
            $this->respondSequence(Response::make('', 500))
        );

        self::assertSame(500, $r->getStatusCode());
        self::assertSame([1], $this->runs, '500 不在默认重试状态码内');
    }

    public function testRespectsMaxAttempts(): void
    {
        $r = $this->mw($this->retry(new FixedBackoff(0.01)), ['attempts' => 2])->process(
            $this->request(),
            $this->respondSequence(Response::make('', 503), Response::make('', 503))
        );

        self::assertSame(503, $r->getStatusCode());
        self::assertSame([1, 2], $this->runs);
        self::assertCount(1, $this->slept);
    }
}
