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
use Webman\Middleware;
use Webman\Config;

// 仅在 Swoole 扩展可用时使用 Swoole 事件循环（高并发远优于默认 Select 循环）；
// 无 ext-swoole 的环境（如 CI/沙箱）自动回退 Workerman 默认循环，保证 peer 可同条件运行。
if (\extension_loaded('swoole')) {
    Worker::$eventLoopClass = SwooleEventLoop::class;
}

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

// 初始化 webman 服务容器（该最小引导未走标准 bootstrap）：
// 直接把 Webman\Container 注入 Config::$config['container']，使
// App::container('') / Middleware 运行期 $container->make() / 异常处理器可用。
// （Config::load 对非数组配置项的处理有坑，故用反射直写静态配置。）
$ref = new ReflectionClass(\Webman\Config::class);
$cfgProp = $ref->getProperty('config');
$cfgProp->setAccessible(true);
$cfgVal = $cfgProp->getValue();
$cfgVal['container'] = new \Webman\Container();
$cfgProp->setValue(null, $cfgVal);

// 加载路由（webman 通过 Route::load 初始化 FastRoute 收集器并 require 各 route.php）。
Route::load([__DIR__ . '/config']);

// 同类型跨切面中间件开启（对标 kode L1/L5）：WEBMAN_MW=on 时注册
// CORS + 安全头 + 链路 ID + 访问日志；关闭（默认）则退化为零中间件（≈ kode L0）。
// 类引用用前导反斜杠的完全限定名，避免在 webman 的 include 上下文里解析错命名空间。
if (($_SERVER['WEBMAN_MW'] ?? getenv('WEBMAN_MW') ?? 'off') === 'on') {
    Middleware::load([
        '@' => [
            \app\middleware\CorsMiddleware::class,
            \app\middleware\SecurityHeadersMiddleware::class,
            \app\middleware\RequestIdMiddleware::class,
            \app\middleware\AccessLogMiddleware::class,
        ],
    ]);
}

// 使用 webman 自带的请求类（带 getRealIp() 等扩展方法）；该 peer 的 composer 仅
// 注册 app\ PSR-4，故不能引用 support\Request（无法自动加载）。Webman\Http\Request
// 已被 webman-framework 正确自动加载，中间件/路由处理器也都以它为类型约束。
$app = new App(\Webman\Http\Request::class, $logger, __DIR__, __DIR__);

$port   = (int) ($_SERVER['BENCH_PORT'] ?? 8091);
$worker = new Worker("http://0.0.0.0:$port");
$worker->count = (int) ($_SERVER['BENCH_WORKERS'] ?? 4);
$worker->onMessage = [$app, 'onMessage'];
// 关键：onWorkerStart 内 Http::requestClass($requestClass) 才会把 workerman
// 实例化请求类从原生 Workerman\Protocols\Http\Request 切换为 Webman\Http\Request；
// 否则原生请求无 getRealIp()，任何中间件抛错都会被 ExceptionHandler 二次崩溃掩盖。
$worker->onWorkerStart = [$app, 'onWorkerStart'];

Worker::runAll();
