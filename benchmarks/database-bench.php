<?php

declare(strict_types=1);

/**
 * 数据库链路微基准（沙箱 PHP 8.3.32 / kode/database 1.15.7 + 框架 v0.8.45）。
 *
 * 用法: php benchmarks/database-bench.php [iterations]
 *
 * 场景口径（同一 pdo-sqlite 内存库，预热 n/10 后计时，3 轮取最小）：
 *   pdo-connection/select    : kode/database PdoConnection::select —— 命中 stmtCache（1.15.7 预编译语句缓存）
 *   raw-pdo/prepare+fetchAll : 原生 PDO 每次 prepare —— 无语句缓存对照组
 *   framework/pool.queryAll  : 框架 ConnectionPool::queryAll —— 每资 prepare + closeCursor 归还
 *
 * 目的：度量 1.15.7 语句缓存在常驻内存热路径上的实际收益（SELECT 单行点查）。
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Database\Connection\PdoConnection;
use Kode\Framework\Database\ConnectionPool;

function bench(string $name, callable $fn, int $n): void
{
    for ($i = 0; $i < (int) ($n / 10); ++$i) {
        $fn();
    }

    $best = PHP_FLOAT_MAX;
    for ($r = 0; $r < 3; ++$r) {
        $t0 = hrtime(true);
        for ($i = 0; $i < $n; ++$i) {
            $fn();
        }
        $best = min($best, hrtime(true) - $t0);
    }

    $perOpNs = $best / $n;
    printf("%-28s %12.0f ops/s  %8.1f ns/op\n", $name, $n / ($best / 1e9), $perOpNs);
}

$n = isset($argv[1]) ? max(10_000, (int) $argv[1]) : 20_000;

// ---------- 装配 pdo-sqlite 内存库 ----------
$rawPdo = new PDO('sqlite::memory:');
$rawPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$rawPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$rawPdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
$ins = $rawPdo->prepare('INSERT INTO users (name, email) VALUES (?, ?)');
for ($i = 0; $i < 1000; ++$i) {
    $ins->execute(["user-{$i}", "u{$i}@example.com"]);
}

// ---------- 1) kode/database 1.15.7（语句缓存路径） ----------
$conn = new PdoConnection([
    'pdo_driver'  => 'sqlite',
    'database'    => ':memory:',
    'username'    => '',
    'password'    => '',
]);
// 预热复用同一 PDO 连接建表与灌数据（与 Connection::select 共用内存库）
$rawPdo = null; // 上面的连接独立于 Connection，改用 Connection 内部连接初始化
$conn->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
$seed = $conn->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['u0', 'u0@example.com']);
for ($i = 1; $i < 1000; ++$i) {
    $conn->insert('INSERT INTO users (name, email) VALUES (?, ?)', ["user-{$i}", "u{$i}@example.com"]);
}
$sql = 'SELECT name, email FROM users WHERE id = ?';

// 缓存命中验证（反射读取 stmtCache）
$ref = new ReflectionProperty($conn, 'stmtCache');
$ref->setAccessible(true);
bench('pdo-connection/select (stmt cache)', fn () => $conn->select($sql, [500]), $n);
printf("    stmtCache 条目数: %d（命中 1 条即验证缓存生效）\n", count($ref->getValue($conn)));

// ---------- 2) 原生 PDO 每次 prepare（对照） ----------
$rawPdo2 = new PDO('sqlite::memory:');
$rawPdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$rawPdo2->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$rawPdo2->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
$ins2 = $rawPdo2->prepare('INSERT INTO users (name, email) VALUES (?, ?)');
for ($i = 0; $i < 1000; ++$i) {
    $ins2->execute(["user-{$i}", "u{$i}@example.com"]);
}
bench('raw-pdo/prepare+fetchAll', function () use ($rawPdo2, $sql): void {
    $stmt = $rawPdo2->prepare($sql);
    $stmt->execute([500]);
    $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
}, (int) ($n * 0.75));

// ---------- 3) 框架 ConnectionPool（每资 prepare + closeCursor 归还） ----------
$pool = new ConnectionPool('sqlite::memory:');
$pool->run(function (PDO $pdo): void {
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO users (name, email) VALUES (?, ?)');
    for ($i = 0; $i < 1000; ++$i) {
        $stmt->execute(["user-{$i}", "u{$i}@example.com"]);
    }
    $pdo->commit();
});
bench('framework/pool.queryAll', fn () => $pool->queryAll($sql, [500]), (int) ($n * 0.75));