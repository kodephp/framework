<?php

declare(strict_types=1);

/**
 * 诊断脚本：隔离 kode 每请求成本的来源。
 * 用法：php -d opcache.enable_cli=1 benchmarks/diag.php
 *
 * 严格复用与真实压测一致的「每请求 Context::run 包裹 + 离路径队列 reset」路径，
 * 仅在路由与中间件配置上做对照，避免脱离生产形态的测量伪影。
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/src/Bench.php';

use Kode\Bench\Bench;
use Kode\Context\Context;
use Kode\Framework\Application;
use Kode\Framework\Http\Resp;
use Kode\Http\App;
use Kode\Framework\Observability\Trace\Tracer;
use Kode\Framework\Logging\AccessLogSink;
use Kode\Framework\Security\Audit\AuditSink;
use Nyholm\Psr7\ServerRequest;

$repoRoot = dirname(__DIR__);
$iters = 4000;
$warmup = 1000;

function prepareRoot(string $repoRoot, array $disable): string
{
    $keys = [...['limiting'], ...$disable];
    $tmp = sys_get_temp_dir() . '/kode-diag-' . substr(md5($repoRoot . '|' . implode(',', $keys)), 0, 10);
    if (!is_dir($tmp . '/config')) {
        mkdir($tmp . '/config', 0o777, true);
        foreach (glob($repoRoot . '/config/*.php') ?: [] as $file) {
            copy($file, $tmp . '/config/' . basename($file));
        }
        putenv('SESSION_DRIVER=array');
        $_ENV['SESSION_DRIVER'] = 'array';
        foreach ($disable as $key) {
            file_put_contents($tmp . '/config/' . $key . '.php', disableSnippet($key));
        }
    }
    return $tmp;
}

function disableSnippet(string $key): string
{
    return match ($key) {
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
    };
}

function boot(string $repoRoot, array $disable): App
{
    $tmp = prepareRoot($repoRoot, $disable);
    $app = Application::make($tmp);
    /** @var App $http */
    $http = $app->http();

    $http->get('/diag/bare', static fn () => new \Kode\Http\Response(200, 'ok'));
    $http->get('/diag/json', static fn () => Resp::json(['x' => 1]));
    $http->get('/diag/di', static function () use ($app) {
        $app->makeService(\Kode\Framework\Resilience\Retry::class);
        return Resp::json(['x' => 1]);
    });
    $http->get('/bench/json', static function () use ($app) {
        $app->makeService(\Kode\Framework\Resilience\Retry::class);
        $data = ['items' => array_map(static fn (int $i) => ['id' => $i], range(1, 50))];
        return Resp::json($data);
    });
    $http->get('/ping', static fn () => Resp::json(['status' => 'ok']));

    return $http;
}

function measureRoute(App $http, string $route): float
{
    global $iters, $warmup;
    // 严格复用 run.php/Kode::scenario 的每请求路径：每次新建 ServerRequest、
    // Context::run 包裹、离路径队列 reset。首次请求捕获会话 Cookie 供后续携带，
    // 模拟真实稳态（避免每次都签发会话导致的测量偏差）。
    $cookie = null;
    $fn = static function () use ($http, $route, &$cookie): int {
        return Context::run(static function () use ($http, $route, &$cookie): int {
            $req = new ServerRequest('GET', $route);
            if ($cookie !== null) {
                $req = $req->withCookieParams($cookie);
            }
            $resp = $http->handle($req);
            if ($cookie === null) {
                $setCookie = $resp->getHeaderLine('Set-Cookie');
                if (preg_match('/KODE_SESSION=([^;]+)/', $setCookie, $m) === 1) {
                    $cookie = ['KODE_SESSION' => $m[1]];
                }
            }
            Tracer::resetOutbox();
            AccessLogSink::reset();
            AuditSink::reset();
            return $resp->getStatusCode();
        });
    };
    return Bench::measure($fn, $warmup, $iters)['ops'];
}

echo "PHP " . PHP_VERSION . " · SAPI " . PHP_SAPI . "\n";
echo str_repeat('=', 70) . "\n";

$kernel = ['logging', 'session', 'idempotency', 'feature', 'cors', 'security', 'locale', 'resilience', 'observability'];
$configs = [
    '全栈(默认)' => [],
    '内核(最小)' => $kernel,
];

foreach ($configs as $label => $disable) {
    $http = boot($repoRoot, $disable);
    echo sprintf("\n[%s]\n", $label);
    foreach (['/diag/bare', '/diag/json', '/diag/di', '/bench/json', '/ping'] as $route) {
        $ops = measureRoute($http, $route);
        printf("  %-14s %12.0f ops/s\n", $route, $ops);
    }
}

echo "\n" . str_repeat('=', 70) . "\n";
echo "[纯构造 / 核心组件微测量]  (ns→s 换算已修正)\n";
$diApp = Application::make(prepareRoot($repoRoot, $kernel));
$micro = static function (callable $fn, int $n = 300000): float {
    $t0 = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $fn();
    }
    $t1 = hrtime(true);
    $sec = ($t1 - $t0) / 1e9;            // 纳秒 → 秒
    return $sec > 0 ? ($n / $sec) : 0.0;
};
$us = static fn (float $ops): string => $ops > 0 ? sprintf('%.3f µs', 1e6 / $ops) : 'n/a';

printf("  new ServerRequest(GET,'/x')  %12.0f ops/s  (%s)\n", ($o = $micro(static fn () => new ServerRequest('GET', '/x'))) , $us($o));
printf("  Context::run(empty)          %12.0f ops/s  (%s)\n", ($o = $micro(static fn () => Context::run(static fn () => null))) , $us($o));
printf("  Resp::json(['x'=>1])         %12.0f ops/s  (%s)\n", ($o = $micro(static fn () => Resp::json(['x' => 1]))) , $us($o));
printf("  Resp::make('ok')             %12.0f ops/s  (%s)\n", ($o = $micro(static fn () => Resp::make('ok'))) , $us($o));
printf("  StringStream::create         %12.0f ops/s  (%s)\n", ($o = $micro(static fn () => \Kode\Http\Psr7\Stream::create('hello'))) , $us($o));
printf("  makeService(Retry)           %12.0f ops/s  (%s)\n", ($o = $micro(static fn () => $diApp->makeService(\Kode\Framework\Resilience\Retry::class))) , $us($o));
printf("  json_encode(50 items)        %12.0f ops/s  (%s)\n", ($o = $micro(static fn () => json_encode(array_map(static fn (int $i) => ['id' => $i], range(1, 50))))) , $us($o));

echo "\n完成。\n";
