<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Idempotency\IdempotencyMiddleware;
use Kode\Framework\Idempotency\StaticIdempotencyManager;
use Kode\Framework\Idempotency\StaticIdempotencyStore;
use Kode\Framework\Http\Resp;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 幂等中间件单元测试（直接驱动中间件 + 内置管理器，不启动整框架）。
 *
 * 验证：
 *  - 首次携带键 → handler 执行，响应带 Idempotency-Recorded: true；
 *  - 重放携带同一键 → handler 不再执行，响应体 + 状态一致，带 Idempotency-Replay: true；
 *  - 缺头（默认配置）→ 直接放行，handler 执行；
 *  - enforce=true 且缺头 → 400；
 *  - 不同键 → 视为不同请求，handler 再次执行。
 */
final class IdempotencyMiddlewareTest extends TestCase
{
    private StaticIdempotencyManager $manager;

    /** @var array<int, string> 记录 handler 每次被执行的入参标识 */
    private array $runs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new StaticIdempotencyManager(new StaticIdempotencyStore([], null));
        $this->runs = [];
    }

    private function handler(string $marker): RequestHandlerInterface
    {
        return new class($marker, $this->runs) implements RequestHandlerInterface {
            /** @param array<int, string> $runs */
            public function __construct(
                private readonly string $marker,
                private array &$runs,
            ) {
            }

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): ResponseInterface
            {
                $this->runs[] = $this->marker;

                return Resp::json(['marker' => $this->marker, 'count' => count($this->runs)]);
            }
        };
    }

    private function mw(array $options = []): IdempotencyMiddleware
    {
        return new IdempotencyMiddleware($this->manager, $options);
    }

    private function request(string $key = '', string $header = 'Idempotency-Key'): ServerRequest
    {
        $r = new ServerRequest('POST', '/api/charge');
        if ($key !== '') {
            $r = $r->withHeader($header, $key);
        }

        return $r;
    }

    public function testFirstRequestRecordsAndRunsHandler(): void
    {
        $r = $this->mw()->process($this->request('k1'), $this->handler('A'));

        self::assertSame(200, $r->getStatusCode());
        self::assertSame('true', $r->getHeaderLine('Idempotency-Recorded'));
        self::assertSame('', $r->getHeaderLine('Idempotency-Replay'));
        self::assertJsonStringEqualsJsonString(
            (string) json_encode(['marker' => 'A', 'count' => 1]),
            (string) $r->getBody()
        );
        self::assertSame(['A'], $this->runs);
    }

    public function testReplayReturnsCachedResponseWithoutRerunning(): void
    {
        $mw = $this->mw();
        $first = $mw->process($this->request('k1'), $this->handler('A'));
        $second = $mw->process($this->request('k1'), $this->handler('A'));

        // handler 只跑一次
        self::assertSame(['A'], $this->runs);

        // 重放响应体与首次一致，状态一致，且带 Replay 头
        self::assertSame((string) $first->getBody(), (string) $second->getBody());
        self::assertSame($first->getStatusCode(), $second->getStatusCode());
        self::assertSame('true', $second->getHeaderLine('Idempotency-Replay'));
        self::assertSame('', $second->getHeaderLine('Idempotency-Recorded'));
    }

    public function testDifferentKeyRunsHandlerAgain(): void
    {
        $mw = $this->mw();
        $mw->process($this->request('k1'), $this->handler('A'));
        $mw->process($this->request('k2'), $this->handler('B'));

        self::assertSame(['A', 'B'], $this->runs);
    }

    public function testMissingHeaderPassesThroughByDefault(): void
    {
        $r = $this->mw()->process($this->request(''), $this->handler('A'));

        self::assertSame(200, $r->getStatusCode());
        self::assertSame('', $r->getHeaderLine('Idempotency-Recorded'));
        self::assertSame('', $r->getHeaderLine('Idempotency-Replay'));
        self::assertSame(['A'], $this->runs);
    }

    public function testEnforceMissingHeaderReturns400(): void
    {
        $r = $this->mw(['enforce' => true])->process($this->request(''), $this->handler('A'));

        self::assertSame(400, $r->getStatusCode());
        self::assertSame([], $this->runs);
    }

    public function testNonJsonBodyPreservedOnReplay(): void
    {
        // 直接用底层 Response 构造纯文本体（Resp::make 会 JSON 编码，这里要测非 JSON 透传）。
        $handler = new class implements RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): ResponseInterface
            {
                return \Kode\Http\Response::make('plain-text-body', 201, ['Content-Type' => 'text/plain']);
            }
        };

        $mw = $this->mw();
        $first = $mw->process($this->request('k1'), $handler);
        $second = $mw->process($this->request('k1'), $handler);

        self::assertSame(201, $second->getStatusCode());
        self::assertSame('text/plain', $second->getHeaderLine('Content-Type'));
        self::assertSame('plain-text-body', (string) $second->getBody());
        self::assertSame((string) $first->getBody(), (string) $second->getBody());
    }

    public function testRouteScopeIsolatesSameKeyAcrossEndpoints(): void
    {
        $mw = $this->mw(['scope' => 'route']);
        // GET /a 与 POST /a 用同一键，route 作用域下互不影响
        $get = $mw->process(
            (new ServerRequest('GET', '/a'))->withHeader('Idempotency-Key', 'k1'),
            $this->handler('G')
        );
        $post = $mw->process(
            (new ServerRequest('POST', '/a'))->withHeader('Idempotency-Key', 'k1'),
            $this->handler('P')
        );

        self::assertSame(['G', 'P'], $this->runs);
        self::assertSame('', $get->getHeaderLine('Idempotency-Replay'));
        self::assertSame('', $post->getHeaderLine('Idempotency-Replay'));
    }
}
