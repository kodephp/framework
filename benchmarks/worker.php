<?php

declare(strict_types=1);

/**
 * 压测 worker（进程隔离测量单元）
 * ---------------------------------
 * 每个场景在**独立 PHP 进程**中 boot + 测量，输出该场景每轮的 ops（JSON 数组）。
 *
 * 为什么需要隔离：
 *   in-process 单进程里「重场景（全栈 /bench/json，触发审计/分配大量对象）」紧跟
 *   「轻场景（内核 /ping）」测量时，会把内核数字从 ~130k 拖到 ~40k（3.3× 摆动）——
 *   根因是 CPU 调频/热节流 + 首场景污染，与框架本身无关。进程隔离后每个场景拥有
 *   干净的启动状态，数字稳定、可复现、可横向比较，honest「同等条件」才成立。
 *
 * 用法（由 run.php 派生调用，勿手动执行）：
 *   php benchmarks/worker.php '<descriptor-json>' <iters> <warmup> <rounds>
 *
 * descriptor 示例：
 *   {"type":"kode","label":"...","route":"/bench/json","disable":["audit"]}
 *   {"type":"baseline","label":"...","route":"(logic)"}
 *   {"type":"slim","label":"...","route":"/ping"}
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/scenarios/kode.php';
require __DIR__ . '/scenarios/baseline.php';
require __DIR__ . '/scenarios/slim.php';
require __DIR__ . '/src/Bench.php';

use Kode\Bench\Scenario\Baseline;
use Kode\Bench\Scenario\Kode;
use Kode\Bench\Scenario\Slim;
use Kode\Bench\Bench;

$repoRoot = dirname(__DIR__);
$peerRoot = __DIR__ . '/peers/slim';

$desc   = json_decode($argv[1] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
$iters  = (int) ($argv[2] ?? 2000);
$warmup = (int) ($argv[3] ?? 500);
$rounds = (int) ($argv[4] ?? 5);

$type = $desc['type'] ?? '';

switch ($type) {
    case 'kode':
        $fn = Kode::scenario($repoRoot, (array) ($desc['disable'] ?? []), (string) $desc['route']);
        break;
    case 'baseline':
        $fn = Baseline::scenario();
        break;
    case 'slim':
        $fn = Slim::scenario($peerRoot, (string) $desc['route']);
        if ($fn === null) {
            echo "null\n";
            exit(0);
        }
        break;
    default:
        fwrite(STDERR, "未知场景类型: $type\n");
        exit(1);
}

// 健康检查：框架场景必须返回 200，否则本场景数据不可信。
$status = $fn();
if ($status !== null && $status !== 200) {
    fwrite(STDERR, "健康检查失败，状态码=" . var_export($status, true) . "\n");
    echo "null\n";
    exit(0);
}

$roundResults = [];
for ($rd = 1; $rd <= $rounds; $rd++) {
    $roundResults[] = Bench::measure($fn, $warmup, $iters);
}

echo json_encode($roundResults) . "\n";
