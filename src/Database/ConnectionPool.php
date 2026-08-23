<?php

declare(strict_types=1);

namespace Kode\Framework\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * 框架级有界 PDO 连接池（per-worker 多进程安全）。
 *
 * 适用于「同步多进程」运行时（kode/process Native 多进程、Workerman、FPM 长驻），
 * 提供与 webman app/DbPool / hyperf/database 在 PDO 层等价的生产级连接管理：
 *  - 每 worker（进程）独立维护一组持久 PDO 连接，进程间不共享可变状态，天然隔离；
 *  - {@see borrow()} 取空闲或新建（不超过 $size），{@see release()} 归还；
 *  - {@see queryOne()}/{@see queryAll()}/{@see run()} 在释放前 closeCursor() 耗尽结果集，
 *    根除「SQLSTATE[HY000]: 2014 Cannot execute queries while other unbuffered queries are active」；
 *  - 连接失效（server has gone away 等）在查询抛 PDOException 时不归还，下次借出重建。
 *
 * 说明：同步单 worker 内请求串行处理，单连接即可；size 仅决定「热身连接数」上限，
 * 真实吞吐差别只在连接管理正确性，不在池大小。
 */
final class ConnectionPool
{
    /** @var PDO[] */
    private array $idle = [];

    private int $created = 0;

    /**
     * @param array<int,mixed> $options PDO 构造选项（默认 ERRMODE_EXCEPTION）
     */
    public function __construct(
        private string $dsn,
        private string $username = '',
        private string $password = '',
        private array $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        private int $size = 4,
    ) {
    }

    public static function mysql(
        string $host,
        int $port,
        string $database,
        string $username,
        string $password,
        int $size = 4,
    ): self {
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

        return new self($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION], $size);
    }

    public static function pgsql(
        string $host,
        int $port,
        string $database,
        string $username,
        string $password,
        int $size = 4,
    ): self {
        $dsn = "pgsql:host={$host};port={$port};dbname={$database}";

        return new self($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION], $size);
    }

    public function borrow(): PDO
    {
        while ($this->idle !== []) {
            return array_pop($this->idle);
        }

        return $this->create();
    }

    public function release(PDO $pdo): void
    {
        if (count($this->idle) < $this->size) {
            $this->idle[] = $pdo;
        }
    }

    /**
     * 取一行；自动 closeCursor 耗尽结果集，归还连接。
     *
     * @param array<int,mixed> $params
     * @return array<string,mixed>|null
     */
    public function queryOne(string $sql, array $params = []): ?array
    {
        $pdo = $this->borrow();
        $failed = false;
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            return $row === false ? null : $row;
        } catch (PDOException $e) {
            $failed = true;

            throw $e;
        } finally {
            if (!$failed) {
                $this->release($pdo);
            }
        }
    }

    /**
     * 取全部行；自动 closeCursor 耗尽结果集，归还连接。
     *
     * @param array<int,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public function queryAll(string $sql, array $params = []): array
    {
        $pdo = $this->borrow();
        $failed = false;
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            return $rows;
        } catch (PDOException $e) {
            $failed = true;

            throw $e;
        } finally {
            if (!$failed) {
                $this->release($pdo);
            }
        }
    }

    /**
     * 借用连接并把 PDO 交给回调；回调返回即释放（closeCursor 由调用方负责）。
     *
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    public function run(callable $fn): mixed
    {
        $pdo = $this->borrow();
        try {
            return $fn($pdo);
        } catch (PDOException $e) {
            // 连接失效：不归还，下次借出重建
            throw $e;
        } finally {
            if ($pdo instanceof PDO) {
                $this->release($pdo);
            }
        }
    }

    public function size(): int
    {
        return $this->size;
    }

    public function created(): int
    {
        return $this->created;
    }

    private function create(): PDO
    {
        // 同步单 worker 借出即归还，正常不会触达上限；仅作防御性报错。
        if ($this->created >= $this->size && $this->idle === []) {
            throw new RuntimeException("ConnectionPool 已达上限 size={$this->size} 且无空闲连接");
        }

        $pdo = new PDO($this->dsn, $this->username, $this->password, $this->options);
        ++$this->created;

        return $pdo;
    }
}
