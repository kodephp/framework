<?php

declare(strict_types=1);

/**
 * webman 对标服务器（常驻内存框架，与 kode 同形态）。
 *
 * 仅镜像两条路由用于压测：/ping（最小响应）与 /bench/json（DI 等价 + 50 条记录 JSON）。
 * 运行：php benchmarks/peers/webman/kode_server.php start -d   （后台常驻）
 * 停止：php benchmarks/peers/webman/kode_server.php stop
 */

require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use Workerman\Events\Swoole as SwooleEventLoop;
use Webman\Route;
use Webman\App;

// 使用 Swoole 事件循环（高并发远优于默认 Select 循环），否则压测下大量连接超时。
Worker::$eventLoopClass = SwooleEventLoop::class;

// 极简 PSR-3 占位 logger（webman App 构造需要；压测不输出日志）。
$logger = new class implements \Psr\Log\LoggerInterface {
    public function emergency($message, array $context = []): void {}
    public function alert($message, array $context = []): void {}
    public function critical($message, array $context = []): void {}
    public function error($message, array $context = []): void {}
    public function warning($message, array $context = []): void {}
    public function notice($message, array $context = []): void {}
    public function info($message, array $context = []): void {}
    public function debug($message, array $context = []): void {}
    public function log($level, $message, array $context = []): void {}
};

// 加载路由（webman 通过 Route::load 初始化 FastRoute 收集器并 require 各 route.php）。
Route::load([__DIR__ . '/config']);

$app = new App(\support\Request::class, $logger, __DIR__, __DIR__);

$port   = (int) ($_SERVER['BENCH_PORT'] ?? 8091);
$worker = new Worker("http://0.0.0.0:$port");
$worker->count = (int) ($_SERVER['BENCH_WORKERS'] ?? 4);
$worker->onMessage = [$app, 'onMessage'];

Worker::runAll();
