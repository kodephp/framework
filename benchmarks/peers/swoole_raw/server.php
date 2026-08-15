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

    $res->status(404);
    $res->header('Content-Type', 'application/json; charset=utf-8');
    $res->end('{"error":"not found"}');
});

echo "Swoole raw server on :$port (workers=$workers)\n";
$server->start();
