<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Database\Db\Db;
use Kode\Framework\Http\Middleware\JsonBodyMiddleware;
use Kode\Framework\Http\Middleware\TransactionMiddleware;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 测试用 spy：改写事务控制三个动作，仅记录调用顺序，避免依赖真实数据库连接
 * （事务原子性由 kode/database 负责，本测试只验证中间件的「编排契约」）。
 */
final class SpyTransactionMiddleware extends TransactionMiddleware
{
    /** @var list<string> */
    public array $calls = [];

    protected function begin(): void
    {
        $this->calls[] = 'begin';
    }

    protected function commit(): void
    {
        $this->calls[] = 'commit';
    }

    protected function rollback(): void
    {
        $this->calls[] = 'rollback';
    }
}

/**
 * 请求级健壮性测试：自动事务中间件、坏 JSON 400 中间件、transaction() 助手。
 */
final class HttpRobustnessTest extends TestCase
{
    /** @var list<string> 临时 sqlite 文件路径，tearDown 清理 */
    private array $tmpFiles = [];

    private function okHandler(ResponseInterface $resp): RequestHandlerInterface
    {
        return new class($resp) implements RequestHandlerInterface {
            public function __construct(private ResponseInterface $r)
            {
            }

            public function handle(ServerRequestInterface $req): ResponseInterface
            {
                return $this->r;
            }
        };
    }

    private function failHandler(\Throwable $e): RequestHandlerInterface
    {
        return new class($e) implements RequestHandlerInterface {
            public function __construct(private \Throwable $e)
            {
            }

            public function handle(ServerRequestInterface $req): ResponseInterface
            {
                throw $this->e;
            }
        };
    }

    // ---------- TransactionMiddleware（编排契约，spy 验证）----------

    public function testDisabledDoesNotOpenTransaction(): void
    {
        $mw = new SpyTransactionMiddleware(enabled: false);
        $resp = $mw->process(new ServerRequest('POST', '/x'), $this->okHandler(new Response(200)));

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame([], $mw->calls);
    }

    public function testReadMethodSkipsTransaction(): void
    {
        $mw = new SpyTransactionMiddleware(enabled: true);
        $mw->process(new ServerRequest('GET', '/x'), $this->okHandler(new Response(200)));

        self::assertSame([], $mw->calls);
    }

    public function testSkipPathSkipsTransaction(): void
    {
        $mw = new SpyTransactionMiddleware(enabled: true, skipPaths: ['/health']);
        $mw->process(new ServerRequest('POST', '/health/live'), $this->okHandler(new Response(200)));

        self::assertSame([], $mw->calls);
    }

    public function testWriteCommitsOnSuccess(): void
    {
        $mw = new SpyTransactionMiddleware(enabled: true);
        $resp = $mw->process(new ServerRequest('POST', '/x'), $this->okHandler(new Response(201)));

        self::assertSame(201, $resp->getStatusCode());
        self::assertSame(['begin', 'commit'], $mw->calls);
    }

    public function testWriteRollsBackAndRethrowsOnFailure(): void
    {
        $mw = new SpyTransactionMiddleware(enabled: true);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        try {
            $mw->process(new ServerRequest('POST', '/x'), $this->failHandler(new \RuntimeException('boom')));
        } catch (\RuntimeException $e) {
            self::assertSame(['begin', 'rollback'], $mw->calls);
            throw $e;
        }
    }

    // ---------- JsonBodyMiddleware ----------

    public function testBadJsonReturns400(): void
    {
        $mw = new JsonBodyMiddleware(enabled: true);
        $req = new ServerRequest('POST', '/api/x', ['Content-Type' => 'application/json'], '{bad');
        $resp = $mw->process($req, $this->okHandler(new Response(200)));

        self::assertSame(400, $resp->getStatusCode());
    }

    public function testValidJsonPasses(): void
    {
        $mw = new JsonBodyMiddleware(enabled: true);
        $req = new ServerRequest('POST', '/api/x', ['Content-Type' => 'application/json'], '{"a":1}');
        $resp = $mw->process($req, $this->okHandler(new Response(200)));

        self::assertSame(200, $resp->getStatusCode());
    }

    public function testFormContentPassesThrough(): void
    {
        $mw = new JsonBodyMiddleware(enabled: true);
        $req = new ServerRequest('POST', '/api/x', ['Content-Type' => 'application/x-www-form-urlencoded'], 'a=1');
        $resp = $mw->process($req, $this->okHandler(new Response(200)));

        self::assertSame(200, $resp->getStatusCode());
    }

    public function testDisabledPassesBadJson(): void
    {
        $mw = new JsonBodyMiddleware(enabled: false);
        $req = new ServerRequest('POST', '/api/x', ['Content-Type' => 'application/json'], '{bad');
        $resp = $mw->process($req, $this->okHandler(new Response(200)));

        self::assertSame(200, $resp->getStatusCode());
    }

    public function testSkipPathPassesBadJson(): void
    {
        $mw = new JsonBodyMiddleware(enabled: true, skipPaths: ['/health']);
        $req = new ServerRequest('POST', '/health', ['Content-Type' => 'application/json'], '{bad');
        $resp = $mw->process($req, $this->okHandler(new Response(200)));

        self::assertSame(200, $resp->getStatusCode());
    }

    // ---------- transaction() 助手（委托 kode/database DB::transaction）----------

    /**
     * 将默认连接切到临时 sqlite 文件（测试环境无 mysql；此文件连接足以支撑
     * begin/commit/rollback 的调用序列验证，无需建表）。
     */
    private function sqliteDefault(): void
    {
        $this->bootApp();

        $tmp = sys_get_temp_dir() . '/kode_tx_' . uniqid() . '.sqlite';
        $this->tmpFiles[] = $tmp;
        Db::addConnection('mem', ['driver' => 'sqlite', 'database' => $tmp]);
        Db::setDefaultConnection('mem');
    }

    public function testTransactionHelperReturnsCallbackValue(): void
    {
        $this->sqliteDefault();
        $result = transaction(function () {
            return 42;
        });

        self::assertSame(42, $result);
    }

    public function testTransactionHelperPropagatesException(): void
    {
        $this->sqliteDefault();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        transaction(function () {
            throw new \RuntimeException('boom');
        });
    }
}
