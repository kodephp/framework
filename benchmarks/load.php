<?php

declare(strict_types=1);

/**
 * 统一 HTTP 压测客户端（基于 Swoole 协程并发）。
 *
 * 用法：php benchmarks/load.php <url> <concurrency> <duration_seconds>
 *   php benchmarks/load.php http://127.0.0.1:8080/ping 50 10
 *
 * 输出 JSON：{reqs, duration, rps, p50, p95, p99, errors}
 *
 * 为什么用真实 HTTP 服务器压测（而非 handle-loop）：webman / hyperf / thinkphp 都是
 * 「服务器型」框架，无法像 kode/Slim 那样在单进程里 boot-once + 循环 handle()。
 * 要「同条件对标」，必须让所有框架都跑在真实 HTTP 服务器上、用同一个并发客户端打流，
 * 这样度量的是「部署态吞吐」（含事件循环 + socket I/O + 框架），最具可比性。
 */

if ($argc < 2) {
    fwrite(STDERR, "用法: php benchmarks/load.php <url> [concurrency=50] [duration=10]\n");
    exit(1);
}

$url        = $argv[1];
$concurrency = (int) ($argv[2] ?? 50);
$duration   = (float) ($argv[3] ?? 10);

$parts = parse_url($url);
$host  = $parts['host'] ?? '127.0.0.1';
$port  = (int) ($parts['port'] ?? 80);
$path  = $parts['path'] ?? '/';
if (($parts['query'] ?? '') !== '') {
    $path .= '?' . $parts['query'];
}

$latencies = [];   // 微秒
$errors    = 0;
$deadline  = 0.0;
$done      = 0;

Swoole\Coroutine\run(static function () use ($host, $port, $path, $concurrency, $duration, &$latencies, &$errors, &$deadline, &$done): void {
    $deadline = microtime(true) + $duration;
    $wg = new Swoole\Coroutine\WaitGroup();

    for ($i = 0; $i < $concurrency; $i++) {
        $wg->add();
        go(static function () use ($host, $port, $path, &$latencies, &$errors, &$deadline, &$done, $wg): void {
            try {
                while (microtime(true) < $deadline) {
                    $client = new Swoole\Coroutine\Http\Client($host, $port);
                    $client->set(['timeout' => 5.0]);
                    $t0 = microtime(true);
                    $ok = $client->get($path);
                    $t1 = microtime(true);
                    $client->close();
                    if ($ok && ($client->statusCode >= 200 && $client->statusCode < 400)) {
                        $latencies[] = ($t1 - $t0) * 1e3;   // ms
                        $done++;
                    } else {
                        $errors++;
                    }
                }
            } catch (\Throwable $e) {
                $errors++;
            } finally {
                $wg->done();
            }
        });
    }

    $wg->wait();
});

sort($latencies);
$n = count($latencies);
$totalDuration = max(0.001, $duration);

$percentile = static function (float $q) use ($latencies, $n): float {
    if ($n === 0) {
        return 0.0;
    }
    $idx = ($q / 100) * ($n - 1);
    $lo  = (int) floor($idx);
    $hi  = (int) ceil($idx);
    if ($lo === $hi) {
        return $latencies[$lo];
    }
    $frac = $idx - $lo;

    return $latencies[$lo] * (1 - $frac) + $latencies[$hi] * $frac;
};

echo json_encode([
    'url'      => $url,
    'concurrency' => $concurrency,
    'duration' => $duration,
    'reqs'     => $done,
    'rps'      => $done / $totalDuration,
    'p50'      => $percentile(50),
    'p95'      => $percentile(95),
    'p99'      => $percentile(99),
    'errors'   => $errors,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
