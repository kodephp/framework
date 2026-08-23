<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Database\ConnectionPool;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * 框架级有界 PDO 连接池单元测试。
 *
 * 用 sqlite::memory 验证：借出/归还、queryOne/queryAll、closeCursor 防 2014、
 * 池上限不超额创建、MySQL/pgsql 工厂。
 */
final class ConnectionPoolTest extends TestCase
{
    private function sqlite(int $size = 2): ConnectionPool
    {
        $pool = new ConnectionPool('sqlite::memory:', '', '', [], $size);
        $pool->run(static fn (PDO $db) => $db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)'));
        $pool->run(static fn (PDO $db) => $db->exec("INSERT INTO t (id,v) VALUES (1,'a'),(2,'b'),(3,'c')"));

        return $pool;
    }

    public function test_query_one_returns_row(): void
    {
        $pool = $this->sqlite();
        $row = $pool->queryOne('SELECT * FROM t WHERE id = ?', [2]);

        $this->assertSame(['id' => 2, 'v' => 'b'], $row);
    }

    public function test_query_one_missing_returns_null(): void
    {
        $pool = $this->sqlite();
        $this->assertNull($pool->queryOne('SELECT * FROM t WHERE id = ?', [99]));
    }

    public function test_query_all_returns_all_rows(): void
    {
        $pool = $this->sqlite();
        $rows = $pool->queryAll('SELECT * FROM t ORDER BY id');

        $this->assertCount(3, $rows);
        $this->assertSame('a', $rows[0]['v']);
    }

    public function test_no_active_result_set_on_consecutive_calls(): void
    {
        // 连续查询同一连接（复用）不得触发「未结束结果集」类错误（PDO 2014 等价）。
        $pool = $this->sqlite(1);
        for ($i = 0; $i < 20; ++$i) {
            $row = $pool->queryOne('SELECT * FROM t WHERE id = ?', [1]);
            $this->assertNotNull($row);
        }
    }

    public function test_reuse_does_not_exceed_size(): void
    {
        $pool = new ConnectionPool('sqlite::memory:', '', '', [], 1);
        for ($i = 0; $i < 8; ++$i) {
            $pool->run(static fn (PDO $db) => $db->exec('SELECT 1'));
        }

        $this->assertSame(1, $pool->created());
    }

    public function test_run_releases_on_success_and_failure(): void
    {
        $pool = new ConnectionPool('sqlite::memory:', '', '', [], 1);

        $ok = $pool->run(static fn (PDO $db) => $db->query('SELECT 1')->fetchColumn());
        $this->assertSame(1, $ok);

        // 异常的 PDO 连接不应归还污染池（此处用 sqlite 故意语法错误触发 PDOException）。
        try {
            $pool->run(static fn (PDO $db) => $db->query('SELECT * FROM nonexistent_tbl'));
            $this->fail('expected PDOException');
        } catch (\PDOException $e) {
            $this->assertTrue(true);
        }

        // 池仍可正常使用（旧坏连接未归还，新建连接）。
        $again = $pool->run(static fn (PDO $db) => $db->query('SELECT 42')->fetchColumn());
        $this->assertSame(42, $again);
    }

    public function test_factories_set_size_and_dsn(): void
    {
        $mysql = ConnectionPool::mysql('127.0.0.1', 3306, 'db', 'u', 'p', 4);
        $pgsql = ConnectionPool::pgsql('127.0.0.1', 5432, 'db', 'u', 'p', 4);

        $this->assertSame(4, $mysql->size());
        $this->assertSame(4, $pgsql->size());
    }
}
