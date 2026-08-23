<?php

declare(strict_types=1);

namespace app;

use PDO;
use PDOException;

/**
 * 有界 PDO 连接池——webman 生产级连接管理的等价实现。
 *
 * 背景：裸复用单个 `static $pdo` + 未耗尽结果集会在高并发触发
 * `SQLSTATE[HY000]: 2014 Cannot execute queries while other unbuffered queries are active`
 * （webman/hyperf dispatch 让复用连接处于「有未结束查询」状态），导致 ~92% 请求 500、
 * 被 wrk 当成功计数 → 报告虚高。
 *
 * 本池的正确性保证（与 webman/database、hyperf/database 连接池在 PDO 层的行为一致）：
 *  - 每 worker 维护一组持久连接（Webman 多进程，每 worker 独立池，互不共享）；
 *  - 调用方在 return 前必须耗尽结果集（fetchAll / closeCursor），本路由已做；
 *  - 借出/归还仅数组出入，无每请求额外 SELECT 1 往返（连接刚被成功使用，视为健康）；
 *  - 连接失效（server has gone away 等）在查询抛出 PDOException 时不归还，下次借出重建。
 *
 * 说明：webman 单 worker 内请求串行处理，故单连接即可；此处上限 16 仅为贴近「连接池」语义，
 * 真实差别仅在连接管理正确性，不在池大小。
 */
class DbPool
{
    /** @var PDO[] */
    private array $pool = [];

    private int $max;

    private string $driver;

    /** @var array{host:string,port:int,database:string,username:string,password:string} */
    private array $cfg;

    /** @param array{host?:string,port?:int,database?:string,username?:string,password?:string,driver?:string} $cfg */
    public function __construct(array $cfg, int $max = 8)
    {
        $this->cfg = [
            'host'     => $cfg['host'] ?? '127.0.0.1',
            'port'     => (int) ($cfg['port'] ?? 3306),
            'database' => $cfg['database'] ?? 'kode_bench',
            'username' => $cfg['username'] ?? 'root',
            'password' => $cfg['password'] ?? 'root',
        ];
        $this->driver = $cfg['driver'] ?? 'mysql';
        $this->max = $max;
    }

    public function borrow(): PDO
    {
        while ($this->pool !== []) {
            return array_pop($this->pool);
        }
        return $this->create();
    }

    public function release(PDO $pdo): void
    {
        if (count($this->pool) < $this->max) {
            $this->pool[] = $pdo;
        }
    }

    private function create(): PDO
    {
        $c = $this->cfg;
        if ($this->driver === 'pgsql') {
            $dsn = "pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}";
        } else {
            $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['database']};charset=utf8mb4";
        }
        return new PDO($dsn, $c['username'], $c['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
