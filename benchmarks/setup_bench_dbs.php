<?php
/**
 * 在 MySQL(3306) 与 pgsql(5432) 各建 kode_bench.bench_users(1000 行，数据一致)，
 * 供 hello world → 真实 DB 业务查询的全频谱压测（多 ORM × 双库）使用。
 *
 * 连法：MySQL root/root；pgsql root（trust，127.0.0.1）。
 */

declare(strict_types=1);

const N = 1000;

function pdoMySql(): PDO
{
    $p = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', 'root', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $p->exec('CREATE DATABASE IF NOT EXISTS kode_bench');
    $p->exec('USE kode_bench');
    return $p;
}

function pdoPgsql(): PDO
{
    $p = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=postgres', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $p->exec('DROP DATABASE IF EXISTS kode_bench');
    $p->exec('CREATE DATABASE kode_bench');
    return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=kode_bench', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

$schemaMysql = <<<'SQL'
DROP TABLE IF EXISTS bench_users;
CREATE TABLE bench_users (
  id INT PRIMARY KEY,
  name VARCHAR(64) NOT NULL,
  email VARCHAR(128) NOT NULL,
  created_at TIMESTAMP NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

$schemaPg = <<<'SQL'
DROP TABLE IF EXISTS bench_users;
CREATE TABLE bench_users (
  id INTEGER PRIMARY KEY,
  name VARCHAR(64) NOT NULL,
  email VARCHAR(128) NOT NULL,
  created_at TIMESTAMP NOT NULL
);
SQL;

function seed(PDO $p, string $driver): void
{
    $base = strtotime('2024-01-01 00:00:00');
    if ($driver === 'mysql') {
        $p->beginTransaction();
        for ($i = 1; $i <= N; $i++) {
            $p->prepare('INSERT INTO bench_users (id,name,email,created_at) VALUES (?,?,?,?)')
                ->execute([$i, "user_$i", "user_$i@bench.local", date('Y-m-d H:i:s', $base + $i)]);
        }
        $p->commit();
    } else {
        $p->beginTransaction();
        for ($i = 1; $i <= N; $i++) {
            $p->prepare('INSERT INTO bench_users (id,name,email,created_at) VALUES (?,?,?,?)')
                ->execute([$i, "user_$i", "user_$i@bench.local", date('Y-m-d H:i:s', $base + $i)]);
        }
        $p->commit();
    }
    $cnt = (int) $p->query('SELECT COUNT(*) FROM bench_users')->fetchColumn();
    echo "  bench_users rows = $cnt\n";
}

echo "=== MySQL ===\n";
$m = pdoMySql();
$m->exec($schemaMysql);
seed($m, 'mysql');

echo "=== pgsql ===\n";
$pg = pdoPgsql();
$pg->exec($schemaPg);
seed($pg, 'pgsql');

echo "DONE\n";
