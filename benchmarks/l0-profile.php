<?php

declare(strict_types=1);

/**
 * L0（完整 PSR-7 内核）请求链分段微基准。
 *
 * 复刻 peer `kode_swoole_server.php`（KODE_PROFILE=off + KODE_RUNTIME=workerman）
 * message 回调的真实三段：HttpBridge::toPsr7 → App::handle → HttpBridge::toRaw。
 * 不含 conn->send（运行时写出，无法在此测）；分段可独立量化包侧/框架侧每次优化的收益。
 *
 * 用法: php benchmarks/l0-profile.php [iterations]
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Http\App;
use Kode\Framework\Http\Resp;
use Kode\Framework\Server\HttpBridge;
use Kode\Process\Http\Request as ProcessRequest;

function benchProfile(string $name, callable $fn, int $n): void
{
    for ($i = 0; $i < (int) ($n / 10); ++$i) {
        $fn();
    }

    $best = PHP_FLOAT_MAX;
    for ($r = 0; $r < 5; ++$r) {
        $t0 = hrtime(true);
        for ($i = 0; $i < $n; ++$i) {
            $fn();
        }
        $best = min($best, hrtime(true) - $t0);
    }

    printf("%-30s %10.0f ops/s  %8.1f ns/op\n", $name, $n / ($best / 1e9), $best / $n);
}

$n = isset($argv[1]) ? max(10_000, (int) $argv[1]) : 100_000;

// 与 peer bootWorker 同构：独立 App + /bench/json 路由（50 条记录，同 webman 对称负载）
$http = new App();
$http->get('/bench/json', static function () {
    $items = array_map(
        static fn (int $i) => ['id' => $i, 'name' => 'item-' . $i],
        range(1, 50)
    );

    return Resp::json(['items' => $items]);
});
$http->get('/bench/ping', static fn () => Resp::json(['status' => 'ok']));

// 分段累计器
$probe = ['toPsr7' => 0, 'handle' => 0, 'toRaw' => 0];

$run = static function (string $path, array &$probe) use ($http, $n): void {
    for ($i = 0; $i < $n; ++$i) {
        $native = ProcessRequest::fromArray([
            'method'   => 'GET',
            'uri'      => $path,
            'protocol' => 'HTTP/1.1',
            'headers'  => ['host' => '127.0.0.1'],
            'body'     => '',
        ]);

        $t0 = hrtime(true);
        $psr = HttpBridge::toPsr7($native);
        $t1 = hrtime(true);
        $response = $http->handle($psr);
        $t2 = hrtime(true);
        $raw = HttpBridge::toRaw($response);
        $t3 = hrtime(true);

        $probe['toPsr7'] += $t1 - $t0;
        $probe['handle'] += $t2 - $t1;
        $probe['toRaw'] += $t3 - $t2;
    }
};

// 预热一轮
$warm = ['toPsr7' => 0, 'handle' => 0, 'toRaw' => 0];
$run('/bench/json', $warm);

echo "payload: /bench/json 50 条记录；/bench/ping 小体\n\n";

foreach (['/bench/json' => 'json50', '/bench/ping' => 'ping'] as $path => $label) {
    $probe = ['toPsr7' => 0, 'handle' => 0, 'toRaw' => 0];
    $t0 = hrtime(true);
    $run($path, $probe);
    $wallNs = hrtime(true) - $t0;
    $total = $probe['toPsr7'] + $probe['handle'] + $probe['toRaw'];
    printf("=== %s ===\n", $label);
    printf("  toPsr7  %8.1f ns/op (%4.1f%%)\n", $probe['toPsr7'] / $n, $probe['toPsr7'] / $total * 100);
    printf("  handle  %8.1f ns/op (%4.1f%%)\n", $probe['handle'] / $n, $probe['handle'] / $total * 100);
    printf("  toRaw   %8.1f ns/op (%4.1f%%)\n", $probe['toRaw'] / $n, $probe['toRaw'] / $total * 100);
    printf("  sum     %8.1f ns/op    wall %8.1f ns/op\n", $total / $n, $wallNs / $n);
}