<?php

declare(strict_types=1);
/**
 * hyperf 对标路由（与 kode/webman/raw 同形态：最小响应 + 业务输出）。
 */
use Hyperf\HttpServer\Router\Router;
use Hyperf\HttpServer\Response;
use Hyperf\DbConnection\Db;

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
// 公平 DB 横比：使用 hyperf/database 生产级连接池（config/autoload/databases.php 的 default 池，
// min 1 / max 10，协程安全——每个协程从池借出独立连接，天然规避裸 $pdo 复用的 PDO 2014）。
// 连接参数已硬编码于 databases.php（127.0.0.1/kode_bench/root/root），与其它 peer 一致。
Router::get('/bench/db', function (Response $response) {
    $id = random_int(1, 1000);
    $user = Db::connection('default')->selectOne('SELECT * FROM bench_users WHERE id = ?', [$id]);
    return $response->json(['user' => $user]);
});

// pgsql 端同等连接池（同条件对比 MySQL vs pgsql 数据）。
Router::get('/bench/db_pg', function (Response $response) {
    $id = random_int(1, 1000);
    $user = Db::connection('pgsql')->selectOne('SELECT * FROM bench_users WHERE id = ?', [$id]);
    return $response->json(['user' => $user]);
});
