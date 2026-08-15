<?php

declare(strict_types=1);

/**
 * Swoole 原生 HTTP Server（常驻内存·无框架中间件）——
 * 作为「同条件压测」的 Swoole 系天花板基线，用于对比 hyperf / kode 的框架开销。
 *
 * 仅镜像两条路由：/ping（hello world）与 /bench/json（50 条记录 JSON）。
 * 运行：php benchmarks/peers/swoole_raw/server.php
 * 端口：BENCH_PORT（默认 8101）。worker 数：BENCH_WORKERS（默认 = CPU 核数）。
 */

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

$port = (int) ($_SERVER['BENCH_PORT'] ?? 8101);
$workers = (int) ($_SERVER['BENCH_WORKERS'] ?? (int) (shell_exec('sysctl -n hw.ncpu') ?: 4));

$server = new Server('0.0.0.0', $port);
$server->set([
    'worker_num' => $workers,
    'enable_coroutine' => false,
]);

$server->on('request', static function (Request $req, Response $res) {
    $uri = $req->server['request_uri'] ?? '/';

    if ($uri === '/ping') {
        $res->header('Content-Type', 'application/json; charset=utf-8');
        $res->end('{"pong":true}');

        return;
    }

    if ($uri === '/bench/json') {
        $items = [];
        for ($i = 1; $i <= 50; ++$i) {
            $items[] = ['id' => $i, 'name' => 'item-' . $i];
        }
        $res->header('Content-Type', 'application/json; charset=utf-8');
        $res->end((string) json_encode(['items' => $items]));

        return;
    }

    // 数据库业务端点：一次主键索引 SELECT + 返回 JSON（与 kode/webman/hyperf 对标同构）。
    // worker 内复用同一 PDO 连接（模拟连接池/持久连接，避免每次建连掩盖查询本身成本）。
    if ($uri === '/bench/db') {
        static $pdo = null;
        if ($pdo === null) {
            $pdo = new PDO(
                'mysql:host=' . ($_SERVER['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($_SERVER['DB_PORT'] ?? 3306)
                    . ';dbname=' . ($_SERVER['DB_DATABASE'] ?? 'kode_bench') . ';charset=utf8mb4',
                $_SERVER['DB_USERNAME'] ?? 'root',
                $_SERVER['DB_PASSWORD'] ?? 'root',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        }
        $id = random_int(1, 1000);
        $stmt = $pdo->prepare('SELECT * FROM bench_users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $res->header('Content-Type', 'application/json; charset=utf-8');
        $res->end((string) json_encode(['user' => $row]));

        return;
    }

    $res->status(404);
    $res->header('Content-Type', 'application/json; charset=utf-8');
    $res->end('{"error":"not found"}');
});

echo "Swoole raw server on :$port (workers=$workers)\n";
$server->start();
