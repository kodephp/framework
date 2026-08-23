<?php

declare(strict_types=1);

/**
 * Workerman 原生 HTTP Server（常驻内存·无框架中间件）——
 * 作为「同条件压测」的 Workerman 系天花板基线，用于对比 webman 的框架开销。
 *
 * 仅镜像两条路由：/ping（hello world）与 /bench/json（50 条记录 JSON）。
 * 运行：php benchmarks/peers/workerman_raw/server.php
 * 端口：BENCH_PORT（默认 8102）。worker 数：BENCH_WORKERS（默认 = CPU 核数）。
 */

require __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

// 仅在 Swoole 扩展可用时使用 Swoole 事件循环；无 ext-swoole 的环境（如 CI/沙箱）
// 自动回退 Workerman 默认循环，保证 peer 可在任意环境同条件运行。
if (\extension_loaded('swoole')) {
    Worker::$eventLoopClass = Workerman\Events\Swoole::class;
}

$port = (int) ($_SERVER['BENCH_PORT'] ?? 8102);
$workers = (int) ($_SERVER['BENCH_WORKERS'] ?? (int) (shell_exec('sysctl -n hw.ncpu') ?: 4));

$worker = new Worker('http://0.0.0.0:' . $port);
$worker->count = $workers;
$worker->name = 'workerman-raw';

$worker->onMessage = static function (TcpConnection $conn, Request $req) {
    $uri = $req->uri();

    if ($uri === '/ping') {
        $conn->send(new Response(200, ['Content-Type' => 'application/json; charset=utf-8'], '{"pong":true}'));

        return;
    }

    if ($uri === '/bench/json') {
        $items = [];
        for ($i = 1; $i <= 50; ++$i) {
            $items[] = ['id' => $i, 'name' => 'item-' . $i];
        }
        $conn->send(new Response(200, ['Content-Type' => 'application/json; charset=utf-8'], (string) json_encode(['items' => $items])));

        return;
    }

    $conn->send(new Response(404, ['Content-Type' => 'application/json; charset=utf-8'], '{"error":"not found"}'));
};

Worker::runAll();
