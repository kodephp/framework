<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Database\Db\Db;
use Kode\Framework\Http\Middleware\ConnectionCleanupMiddleware;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 连接生命周期收口中间件（spy：仅记录回滚/释放动作，不直接触碰真实连接）。
 */
final class SpyCleanupMiddleware extends ConnectionCleanupMiddleware
{
    /** @var list<string> */
    public array $calls = [];

    public bool $fakeInTx = false;

    protected function inTransaction(): bool
    {
        return $this->fakeInTx;
    }

    protected function rollbackLeaked(): void
    {
        $this->calls[] = 'rollback';
    }

    protected function releaseConnections(): void
    {
        $this->calls[] = 'disconnect';
    }
}

/**
 * 连接生命周期收口 + 事务原子性（验证 kode/database 1.15.5 连接缓存修复）。
 */
final class ConnectionLifecycleTest extends TestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        // kode/database 的连接池是进程级静态态，清理以避免跨测试复用（尤其泄漏事务场景）。
        try {
            Db::disconnect();
        } catch (\Throwable) {
            // 忽略。
        }
        parent::tearDown();
    }

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

    // ---------- ConnectionCleanupMiddleware 编排契约（spy）----------

    public function testHealthyPathTouchesNothing(): void
    {
        $mw = new SpyCleanupMiddleware();
        $resp = $mw->process(new ServerRequest('GET', '/x'), $this->okHandler(new Response(200)));

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame([], $mw->calls);
    }

    public function testLeakedTransactionIsRolledBack(): void
    {
        $mw = new SpyCleanupMiddleware();
        $mw->fakeInTx = true;

        $resp = $mw->process(new ServerRequest('GET', '/x'), $this->okHandler(new Response(200)));

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame(['rollback'], $mw->calls);
    }

    public function testReleasePerRequestDisconnects(): void
    {
        $mw = new SpyCleanupMiddleware(releasePerRequest: true);

        $mw->process(new ServerRequest('GET', '/x'), $this->okHandler(new Response(200)));

        self::assertSame(['disconnect'], $mw->calls);
    }

    public function testExceptionStillTriggersCleanupAndRethrows(): void
    {
        $mw = new SpyCleanupMiddleware();
        $mw->fakeInTx = true;

        try {
            $mw->process(new ServerRequest('GET', '/x'), $this->failHandler(new \RuntimeException('boom')));
            self::fail('预期异常未抛出');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
            // finally 在异常传播前执行：泄漏事务被回滚，原始异常继续向上传递。
            self::assertSame(['rollback'], $mw->calls);
        }
    }

    // ---------- 真实事务原子性（证明 kode/database 连接缓存修复）----------

    /**
     * 把默认连接切到临时 sqlite 文件（无需外部 mysql），并建一张测试表。
     */
    private function sqliteDefault(string $name): void
    {
        $tmp = sys_get_temp_dir() . '/kode_lc_' . uniqid() . '.sqlite';
        $this->tmpFiles[] = $tmp;

        Db::addConnection($name, ['driver' => 'sqlite', 'database' => $tmp]);
        Db::setDefaultConnection($name);
        Db::statement('CREATE TABLE IF NOT EXISTS lc_tx (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)');
        Db::statement('DELETE FROM lc_tx');
    }

    /**
     * 回滚必须丢弃本事务内的写入（begin/insert/rollback 在同一连接上）。
     * 修复前 getConnection() 每次新建连接，insert 在另一条连接上自动提交，回滚无效 → 此测试失败。
     */
    public function testRollbackDiscardsWrites(): void
    {
        $this->sqliteDefault('lc_rollback');

        Db::beginTransaction();
        Db::insert('INSERT INTO lc_tx (v) VALUES (?)', ['a']);
        Db::rollback();

        self::assertSame([], Db::select('SELECT * FROM lc_tx'));
    }

    /**
     * 提交必须持久化本事务内的写入（begin/insert/commit 在同一连接上）。
     */
    public function testCommitPersistsWrites(): void
    {
        $this->sqliteDefault('lc_commit');

        Db::beginTransaction();
        Db::insert('INSERT INTO lc_tx (v) VALUES (?)', ['b']);
        Db::commit();

        $rows = Db::select('SELECT * FROM lc_tx');
        self::assertCount(1, $rows);
        self::assertSame('b', $rows[0]['v']);
    }

    /**
     * 事务进行中读操作能看到未提交写入（read-your-writes）：同一连接上 begin→insert→select
     * 应返回本事务数据；提交后连接池仍按同一连接复用，避免读写分离导致的读不到未提交数据。
     */
    public function testReadYourWritesInTransaction(): void
    {
        $this->sqliteDefault('lc_ryw');

        Db::beginTransaction();
        Db::insert('INSERT INTO lc_tx (v) VALUES (?)', ['c']);
        // 事务内读（未提交）应能看到本行。
        self::assertCount(1, Db::select('SELECT * FROM lc_tx WHERE v = ?', ['c']));

        Db::rollback();
        self::assertSame([], Db::select('SELECT * FROM lc_tx'));
    }
}
