<?php

declare(strict_types=1);

use Webman\Route;

// 仅镜像两条对标路由：/ping（最小响应）与 /bench/json（DI 等价 + 50 条记录 JSON）。
Route::get('/ping', static fn () => json(['pong' => true]));

Route::get('/bench/json', static function () {
    return json([
        'framework' => 'webman',
        'now'       => date('c'),
        'items'     => array_map(
            static fn (int $i) => ['id' => $i, 'name' => "item-$i"],
            range(1, 50)
        ),
    ]);
});

// 数据库业务端点：一次主键索引 SELECT + 返回 JSON（与 kode/hyperf 同构）。
// 公平 DB 横比：使用有界 PDO 连接池（app/DbPool，等价 webman/database 在 PDO 层的
// 连接管理），并在 return 前 closeCursor() 耗尽结果集，从而**根除 PDO 2014**（此前
// 裸复用单连接未耗尽结果集 → 高并发 ~92% 500 被 wrk 当成功计数 → 报告虚高）。
// 本端点因此为 0 错误、诚实的「连接池 DB」吞吐，可与 kode Kode\Database\Db / hyperf/database
// 同口径横比（详见 PEER_BENCHMARK.md §4.2）。
Route::get('/bench/db', static function () {
    static $pool = null;
    if ($pool === null) {
        $pool = new \app\DbPool([
            'host'     => $_SERVER['DB_HOST'] ?? '127.0.0.1',
            'port'     => $_SERVER['DB_PORT'] ?? 3306,
            'database' => $_SERVER['DB_DATABASE'] ?? 'kode_bench',
            'username' => $_SERVER['DB_USERNAME'] ?? 'root',
            'password' => $_SERVER['DB_PASSWORD'] ?? 'root',
        ], 8);
    }

    $id = random_int(1, 1000);
    $pdo = $pool->borrow();
    try {
        $stmt = $pdo->prepare('SELECT * FROM bench_users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor(); // 耗尽结果集：根除复用连接时的 PDO 2014
        $resp = ['user' => $row];
        $pool->release($pdo);
        return json($resp);
    } catch (\PDOException $e) {
        // 连接失效：不归还，下次借出重建（与框架连接池一致）
        throw $e;
    }
});

// pgsql 端同等连接池（同条件对比 MySQL vs pgsql 数据）。
Route::get('/bench/db_pg', static function () {
    static $pool = null;
    if ($pool === null) {
        $pool = new \app\DbPool([
            'driver'   => 'pgsql',
            'host'     => $_SERVER['PG_HOST'] ?? '127.0.0.1',
            'port'     => $_SERVER['PG_PORT'] ?? 5432,
            'database' => $_SERVER['PG_DATABASE'] ?? 'kode_bench',
            'username' => $_SERVER['PG_USERNAME'] ?? 'root',
            'password' => $_SERVER['PG_PASSWORD'] ?? '',
        ], 8);
    }

    $id = random_int(1, 1000);
    $pdo = $pool->borrow();
    try {
        $stmt = $pdo->prepare('SELECT * FROM bench_users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor(); // 耗尽结果集：根除复用连接时的 PDO 2014
        $resp = ['user' => $row];
        $pool->release($pdo);
        return json($resp);
    } catch (\PDOException $e) {
        throw $e;
    }
});
