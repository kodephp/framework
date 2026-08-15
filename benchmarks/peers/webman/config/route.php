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

// 数据库业务端点：一次主键索引 SELECT + 返回 JSON（与 kode/swoole_raw 同构）。
// worker 内复用同一 PDO 连接（模拟连接池），隔离「框架管道」在 DB 路径上的开销。
Route::get('/bench/db', static function () {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new \PDO(
            'mysql:host=' . ($_SERVER['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($_SERVER['DB_PORT'] ?? 3306)
                . ';dbname=' . ($_SERVER['DB_DATABASE'] ?? 'kode_bench') . ';charset=utf8mb4',
            $_SERVER['DB_USERNAME'] ?? 'root',
            $_SERVER['DB_PASSWORD'] ?? 'root',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }
    $id = random_int(1, 1000);
    $stmt = $pdo->prepare('SELECT * FROM bench_users WHERE id = ?');
    $stmt->execute([$id]);

    return json(['user' => $stmt->fetch(\PDO::FETCH_ASSOC)]);
});
