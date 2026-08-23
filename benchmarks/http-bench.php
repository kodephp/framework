<?php

declare(strict_types=1);

/**
 * HTTP 响应链热路径微基准（沙箱 PHP 8.3.32 / kode/http 3.4.7 + 框架 v0.8.46）。
 *
 * 用法: php benchmarks/http-bench.php [iterations]
 *
 * 场景对应 PEER_BENCHMARK.md 的两条对标路由：
 *   /ping        : `new Response(200, [], 'ok')` 小体字符串响应
 *   /bench/json  : 内存构造 50 条记录 JSON 响应（与 webman/hyperf /bench/json 同 payload）
 *
 * 口径：预热 n/10 后计时，3 轮取最小；测量「响应体构造 + 体内存化」的服务端热路径成本
 * （不含事件循环/连接/路由，那部分受运行时与桥接影响，见 PEER_BENCHMARK）。
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Http\Response;

function bench(string $name, callable $fn, int $n): void
{
    for ($i = 0; $i < (int) ($n / 10); ++$i) {
        $fn();
    }

    $best = PHP_FLOAT_MAX;
    for ($r = 0; $r < 3; ++$r) {
        $t0 = hrtime(true);
        for ($i = 0; $i < $n; ++$i) {
            $fn();
        }
        $best = min($best, hrtime(true) - $t0);
    }

    $perOpNs = $best / $n;
    printf("%-34s %12.0f ops/s  %8.1f ns/op\n", $name, $n / ($best / 1e9), $perOpNs);
}

$n = isset($argv[1]) ? max(10_000, (int) $argv[1]) : 100_000;

$records = [];
for ($i = 0; $i < 50; ++$i) {
    $records[] = ['id' => $i, 'name' => "user-{$i}", 'email' => "u{$i}@example.com", 'active' => true];
}
$json50 = json_encode($records, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$jsonLen = strlen($json50);

echo "payload /bench/json: {$jsonLen} bytes（50 条记录）\n\n";

// --- /bench/json：kode 3.4.7 快速路径（json() rawBody → SwooleServerAdapter 直取字符串体）---
bench('json50 + getBodyString (kode fast)', function () use ($records): void {
    $r = Response::json($records);
    $body = $r->getBodyString();
    if ($body === '') {
        throw new RuntimeException('unexpected');
    }
}, $n);

// --- /bench/json：PSR-7 通用路径（第三方响应才会走，旧实现每次 Stream 物化）---
bench('json50 + getBody()->getContents()', function () use ($records): void {
    $r = Response::json($records);
    $body = $r->getBody()->getContents();
    if ($body === '') {
        throw new RuntimeException('unexpected');
    }
}, $n);

// --- /bench/json：原生 json_encode + 字符串（webman 形态基线）---
bench('native json_encode only', function () use ($records): void {
    $json = json_encode($records, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('json_encode failed');
    }
}, $n);

// --- /ping：小体字符串响应（kode 快速路径）---
bench('/ping + getBodyString (kode fast)', function (): void {
    $r = new Response(200, [], 'ok');
    $body = $r->getBodyString();
    if ($body !== 'ok') {
        throw new RuntimeException('unexpected');
    }
}, $n);

// --- /ping：PSR-7 通用路径 ---
bench('/ping + getBody()->getContents()', function (): void {
    $r = new Response(200, [], 'ok');
    $body = $r->getBody()->getContents();
    if ($body !== 'ok') {
        throw new RuntimeException('unexpected');
    }
}, $n);