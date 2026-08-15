<?php

declare(strict_types=1);

/**
 * kode 对标服务器（Swoole HTTP Server 包裹 kode App::handle，与 webman/hyperf 同形态）。
 *
 * 关键：每个 worker 在自己的 WorkerStart 里独立 boot kode（而非 master boot 后 fork 继承共享状态），
 * 否则多 worker 高并发下共享静态/资源状态会损坏导致 worker 崩溃。
 *
 * 仅镜像两条路由：/ping 与 /bench/json（与 benchmarks/scenarios/kode.php 一致）。
 * 运行：php benchmarks/peers/kode_swoole_server.php   （前台运行，Ctrl+C 停止）
 * 端口：BENCH_PORT（默认 8093）。worker 数：BENCH_WORKERS（默认 4）。
 *
 * 配置档（KODE_PROFILE，默认 default）：
 *  - default：完整企业级中间件栈（与框架开箱默认一致），用于「同条件对等」对比。
 *  - lean：关闭非必需中间件（日志/会话/幂等/特性/CORS/安全头/国际化/韧性/可观测），
 *          仅保留路由+异常+连接收口，用于观察「框架自身开销」天花板。
 *
 * 注：无论哪种档位，/ping 与 /bench/json 都不触碰会话，故 SESSION_DRIVER=array
 * 仅为避免 worker 退出时任何意外落盘，不影响吞吐对比。
 */

require __DIR__ . '/../../vendor/autoload.php';

use Kode\Framework\Application;
use Kode\Http\App;
use Kode\Framework\Http\Resp;
use Kode\Framework\Observability\Trace\Tracer;
use Kode\Framework\Logging\AccessLogSink;
use Kode\Framework\Security\Audit\AuditSink;
use Nyholm\Psr7\ServerRequest;

$repoRoot = dirname(__DIR__, 2);

$profile = $_SERVER['KODE_PROFILE'] ?? 'default'; // 'default' | 'lean'
// 压测剖析：KODE_DISABLE 可追加额外关闭的中间件组（逗号分隔），用于定位各组边际成本。
$extraDisable = [];
if (!empty($_SERVER['KODE_DISABLE'])) {
    $extraDisable = array_filter(array_map('trim', explode(',', (string) $_SERVER['KODE_DISABLE'])));
}
// 全局限流是策略开关（config/limiting.php 默认 capacity=10/s），压测吞吐前关闭，
// 否则测得的是限流器而非框架开销；webman/hyperf 默认均无全局限流，故 kode 在对比中同样关闭以对齐「同条件」。
$alwaysDisable = ['limiting'];
$profileDisable = $profile === 'lean'
    ? ['logging', 'session', 'idempotency', 'feature', 'cors', 'security', 'locale', 'resilience', 'observability']
    : [];
$disable = array_values(array_unique([...$alwaysDisable, ...$profileDisable, ...$extraDisable]));
$keys = $disable;
$tmp = sys_get_temp_dir() . '/kode-peer-' . substr(md5($repoRoot . '|' . $profile . '|' . implode(',', $keys)), 0, 10);
if (!is_dir($tmp . '/config')) {
    mkdir($tmp . '/config', 0o777, true);
    foreach (glob($repoRoot . '/config/*.php') ?: [] as $file) {
        copy($file, $tmp . '/config/' . basename($file));
    }
    putenv('SESSION_DRIVER=array');
    $_ENV['SESSION_DRIVER'] = 'array';
    foreach ($disable as $k) {
        file_put_contents($tmp . '/config/' . $k . '.php', match ($k) {
            'logging'     => "<?php return ['access_log' => ['enabled' => false]];\n",
            'session'     => "<?php return ['enabled' => false];\n",
            'idempotency' => "<?php return ['http' => ['enabled' => false]];\n",
            'feature'    => "<?php return ['enabled' => false];\n",
            'cors'       => "<?php return ['enabled' => false];\n",
            'security'   => "<?php return ['enabled' => false, 'audit' => ['enabled' => false]];\n",
            'locale'     => "<?php return ['enabled' => false];\n",
            'resilience' => "<?php return ['breaker' => ['http' => ['enabled' => false]], 'retry' => ['http' => ['enabled' => false]]];\n",
            'observability' => "<?php return ['metrics' => ['enabled' => false], 'tracing' => ['enabled' => false]];\n",
            'audit'      => "<?php return ['audit' => ['enabled' => false]];\n",
            'tracing'    => "<?php return ['tracing' => ['enabled' => false]];\n",
            default      => "<?php return ['enabled' => false];\n",
        });
    }
}

// worker 本地持有的 kode App（在 WorkerStart 里赋值）。
$state = new class {
    public ?App $http = null;
};

$port = (int) ($_SERVER['BENCH_PORT'] ?? 8093);
$workers = (int) ($_SERVER['BENCH_WORKERS'] ?? 4);

$server = new Swoole\Http\Server('0.0.0.0', $port);
$server->set(['worker_num' => $workers, 'enable_coroutine' => false]);

// 每个 worker 独立 boot kode（master 不持有业务状态）。
$server->on('WorkerStart', static function () use ($tmp, $state): void {
    $app = Application::make($tmp);
    /** @var App $http */
    $http = $app->http();
    $http->get('/ping', static fn () => Resp::json(['status' => 'ok']));
    $http->get('/bench/json', static function () use ($app) {
        // 业务输出代理：与 peer 一致的内存构造 50 条记录（无 DB，隔离框架开销）。
        $items = [];
        for ($i = 1; $i <= 50; ++$i) {
            $items[] = ['id' => $i, 'name' => 'item-' . $i];
        }

        return Resp::json(['items' => $items]);
    });
    $state->http = $http;
});

$server->on('request', static function (Swoole\Http\Request $req, Swoole\Http\Response $res) use ($state): void {
    $http = $state->http;
    if ($http === null) {
        $res->status(503);
        $res->end('not ready');

        return;
    }

    $uri = $req->server['request_uri'] ?? '/';
    $method = $req->server['request_method'] ?? 'GET';
    $headers = $req->header ?: [];
    $body = (string) $req->rawContent();

    $psr = new ServerRequest($method, $uri, $headers, $body);
    if (!empty($req->get)) {
        $psr = $psr->withQueryParams($req->get);
    }
    if (!empty($req->cookie)) {
        $psr = $psr->withCookieParams($req->cookie);
    }

    $response = $http->handle($psr);

    Tracer::resetOutbox();
    AccessLogSink::reset();
    AuditSink::reset();

    $res->status($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $v) {
            $res->header($name, $v);
        }
    }
    $res->end((string) $response->getBody());
});

echo "kode Swoole peer server on :$port (workers=$workers)\n";
$server->start();
