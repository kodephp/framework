<?php

declare(strict_types=1);
/**
 * hyperf 对标路由（与 kode/webman/raw 同形态：最小响应 + 业务输出）。
 */
use Hyperf\HttpServer\Router\Router;
use Hyperf\HttpServer\Response;

Router::get('/ping', function (Response $response) {
    return $response->json(['pong' => true]);
});

Router::get('/bench/json', function (Response $response) {
    $items = [];
    for ($i = 1; $i <= 50; ++$i) {
        $items[] = ['id' => $i, 'name' => 'item-' . $i];
    }

    return $response->json(['items' => $items]);
});

// 数据库业务端点：一次主键索引 SELECT + 返回 JSON（与其它常驻框架 peer 同构）。
Router::get('/bench/db', function (Response $response) {
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

    return $response->json(['user' => $stmt->fetch(\PDO::FETCH_ASSOC)]);
});
