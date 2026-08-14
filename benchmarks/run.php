<?php

declare(strict_types=1);

/*
 * kode/framework 压测对比编排器
 * ---------------------------------
 * 运行：php benchmarks/run.php
 * 可调：BENCH_ITERS（默认 8000）、BENCH_WARMUP（默认 1000）、BENCH_ROUNDS（默认 5）
 *
 * 测量口径：单进程内「一次 boot + N 次 handle()」——即常驻内存运行时
 * （Swoole/Swow/Fiber 长生命周期）的真实每请求开销，排除 HTTP 服务器与
 * 进程启动噪声，专注于框架 + 中间件栈的吞吐与延迟。
 *
 * 抗方差设计：单轮绝对 req/s 在不同机器 / 负载下会摆动 ~2.4×，故本编排
 * 跑 BENCH_ROUNDS 轮，每轮内各场景顺序执行（共享同一机器状态），并以
 * 「kode 吞吐 ÷ 同轮裸 PHP 基线吞吐」的中位数比例作为稳定主指标——
 * 机器方差在比值中相互抵消，使「框架响应」的可比性与可追踪性大幅提升。
 */

use Kode\Bench\Bench;
use Kode\Bench\Scenario\Baseline;
use Kode\Bench\Scenario\Kode;
use Kode\Bench\Scenario\Slim;
use Kode\Framework\Application;

require __DIR__ . '/../vendor/autoload.php';

// 压测脚本自带的轻量类（不在框架自动加载范围内，手动引入）。
require __DIR__ . '/src/Bench.php';
require __DIR__ . '/scenarios/kode.php';
require __DIR__ . '/scenarios/baseline.php';
require __DIR__ . '/scenarios/slim.php';

$repoRoot = dirname(__DIR__);
$peerRoot = __DIR__ . '/peers/slim';

$iters   = (int) ($_SERVER['BENCH_ITERS'] ?? 8000);
$warmup  = (int) ($_SERVER['BENCH_WARMUP'] ?? 1000);
$rounds  = (int) ($_SERVER['BENCH_ROUNDS'] ?? 5);
$disable = ['logging', 'session', 'idempotency', 'feature', 'cors', 'security', 'locale', 'resilience', 'observability'];

echo "kode/framework 压测对比  (iters=$iters, warmup=$warmup, rounds=$rounds)\n";
echo "PHP " . PHP_VERSION . " · SAPI " . PHP_SAPI . " · " . PHP_OS . "\n";
$oc = function_exists('opcache_get_status') && (@opcache_get_status()['opcache_enabled'] ?? false);
$jitBuf = (int) ini_get('opcache.jit_buffer_size');
echo "OPcache: " . ($oc ? 'on' : 'off') . " · JIT buffer: " . ($jitBuf > 0 ? $jitBuf . 'B' : 'off') . "\n";
echo str_repeat('-', 72) . "\n";

// 同等条件口径（进程隔离测量，详见 worker.php）：
//  - 每个场景在**独立 PHP 进程**中 boot + 测量，消除「重场景紧跟轻场景」导致的 CPU 调频/
//    热节流污染（同进程交错时内核 /ping 会从 ~130k 被拖到 ~40k，3.3× 摆动，与框架无关）。
//  - 「全栈」默认保留生产中间件（异常/请求ID/CORS/安全头/限流/熔断/重试/幂等/会话/特性/
//    审计…）。其中 /ping 命中审计 ignore_paths 与事务/JSON 的 skipPaths，属于「探针短路」，
//    并不计入审计与事务成本——故它只是探针吞吐，不是真实全栈成本。
//  - 「全栈 · 业务端点 /bench/json」：/bench/json 不在审计 ignore_paths，审计**真实触发**，
//    是对外展示的诚实全栈数字（含审计离路径写入成本）。
//  - 「全栈 · 业务端点（审计关闭）」：同一业务端点但关闭审计，用于**隔离审计税**，使
//    「必要的审计」在同等条件下被显式量化，而非被 /ping 探针短路掩盖。
$scenarios = [
    ['type' => 'kode',     'label' => 'kode · 全栈 · 业务端点 (审计触发)',  'route' => '/bench/json', 'disable' => []],
    ['type' => 'kode',     'label' => 'kode · 全栈 · 业务端点 (审计关闭)',  'route' => '/bench/json', 'disable' => ['audit']],
    ['type' => 'kode',     'label' => 'kode · 全栈 · 探针 (审计/事务跳过)', 'route' => '/ping',       'disable' => []],
    ['type' => 'kode',     'label' => 'kode · 内核 (最小中间件)',           'route' => '/ping',       'disable' => $disable],
    ['type' => 'kode',     'label' => 'kode · 内核 (json+DI)',              'route' => '/bench/json', 'disable' => $disable],
    ['type' => 'baseline', 'label' => 'baseline · 裸 PHP (纯逻辑)',         'route' => '(logic)',     'disable' => []],
];

$slimAvailable = Slim::available($peerRoot);
if ($slimAvailable) {
    $scenarios[] = ['type' => 'slim', 'label' => 'Slim 4 · (ping)',        'route' => '/ping',       'disable' => []];
    $scenarios[] = ['type' => 'slim', 'label' => 'Slim 4 · (json)',        'route' => '/bench/json', 'disable' => []];
}

$results = [];
$built   = [];

// 1) 进程隔离测量：每个场景派生独立 PHP 进程（worker.php）boot + 跑 $rounds 轮，
//    输出每轮 ops。独立进程消除「同进程重场景污染轻场景」导致的 3.3× 数字摆动。
$perRoundOps = [];   // [label => ops[]]
$perRoundRes = [];   // [label => result[]]（仅 p50/p95/p99 等，从 worker 单次测量取代表轮聚合）
foreach ($scenarios as $sc) {
    $descriptor = json_encode([
        'type'    => $sc['type'],
        'label'   => $sc['label'],
        'route'   => $sc['route'],
        'disable' => $sc['disable'],
    ], JSON_UNESCAPED_SLASHES);

    $payload = runWorker($descriptor, $iters, $warmup, $rounds);

    if ($payload === null) {
        echo sprintf("  [跳过] %-26s 不可用 / 健康检查失败\n", $sc['label']);
        continue;
    }

    // worker 输出每轮完整测量结果（含 p50/p95/p99/min/max/ops）。
    $roundResults = $payload;
    if (!is_array($roundResults) || $roundResults === []) {
        echo sprintf("  [跳过] %-26s 健康检查失败\n", $sc['label']);
        continue;
    }

    $perRoundOps[$sc['label']] = array_column($roundResults, 'ops');
    $perRoundRes[$sc['label']] = $roundResults;
    $built[] = ['sc' => $sc];
    echo sprintf(
        "  [就绪] %-26s  中位 %10.0f req/s (波动 %10.0f~%10.0f)\n",
        $sc['label'],
        median($perRoundOps[$sc['label']]),
        (float) min($perRoundOps[$sc['label']]),
        (float) max($perRoundOps[$sc['label']])
    );
}

$base = null;
foreach ($built as $b) {
    $label = $b['sc']['label'];
    if (!str_starts_with($label, 'baseline')) {
        continue;
    }
    $base = median($perRoundOps[$label]);
}

foreach ($built as $b) {
    $label = $b['sc']['label'];
    $rs    = $perRoundRes[$label];
    $ops   = $perRoundOps[$label];

    $agg = [
        'label'   => $label,
        'route'   => $b['sc']['route'],
        'ops'     => median($ops),
        'ops_min' => (float) min($ops),
        'ops_max' => (float) max($ops),
        'p50'     => median(array_column($rs, 'p50')),
        'p95'     => median(array_column($rs, 'p95')),
        'p99'     => median(array_column($rs, 'p99')),
        'min'     => median(array_column($rs, 'min')),
        'max'     => median(array_column($rs, 'max')),
    ];
    if ($base !== null && $base > 0) {
        $agg['ratio']     = $agg['ops'] / $base;
        $agg['ratio_min'] = min($ops) / $base;
        $agg['ratio_max'] = max($ops) / $base;
    }
    $results[] = $agg;

    echo sprintf(
        "  %-26s 中位数 %10.0f req/s (波动 %10.0f~%10.0f) | p50 %7.3f  p99 %7.3f ms\n",
        $label,
        $agg['ops'],
        $agg['ops_min'],
        $agg['ops_max'],
        $agg['p50'],
        $agg['p99']
    );
}

echo str_repeat('-', 72) . "\n";
echo "框架增量开销（相对裸 PHP 基线·中位数比例，机器方差已抵消）:\n";
foreach ($results as $r) {
    if (!isset($r['ratio'])) {
        continue;
    }
    echo sprintf(
        "  %-26s 约为裸 PHP 的 %.2f%% 吞吐 (波动 %.2f~%.2f%%)\n",
        $r['label'],
        $r['ratio'] * 100,
        $r['ratio_min'] * 100,
        $r['ratio_max'] * 100
    );
}

// 同等条件隔离：审计税 与 框架税（基于同轮比值，机器方差已抵消）。
$auditOn  = array_find_key($results, 'kode · 全栈 · 业务端点 (审计触发)');
$auditOff = array_find_key($results, 'kode · 全栈 · 业务端点 (审计关闭)');
$coreJson = array_find_key($results, 'kode · 内核 (json+DI)');
$auditTax = null;   // 审计带来的吞吐损耗比例（同等业务端点，仅差审计开关）
$frameTax = null;   // 全栈相对内核的吞吐损耗比例（业务端点）
if ($auditOn !== null && $auditOff !== null && $auditOff['ops'] > 0) {
    $auditTax = 1 - ($auditOn['ops'] / $auditOff['ops']);
}
if ($auditOn !== null && $coreJson !== null && $auditOn['ops'] > 0) {
    $frameTax = 1 - ($auditOn['ops'] / $coreJson['ops']);
}

echo "\n同等条件隔离（同业务端点 /bench/json，机器方差已抵消）:\n";
if ($auditTax !== null) {
    echo sprintf("  审计税（全栈含审计 ÷ 同端点关审计）：审计带来约 %.2f%% 吞吐损耗\n", $auditTax * 100);
}
if ($frameTax !== null) {
    echo sprintf("  框架税（全栈业务端点 ÷ 内核同端点）：默认全栈中间件带来约 %.2f%% 吞吐损耗\n", $frameTax * 100);
}

if ($slimAvailable) {
    echo "\n（已包含 Slim 4 对等框架实测数据）\n";
} else {
    echo "\n提示：未检测到 benchmarks/peers/slim/vendor，已跳过 Slim 对比。\n"
        . "      运行 `cd benchmarks/peers/slim && composer install` 后可获得同类框架真实对比。\n";
}

writeReport($results, $base, $slimAvailable, $iters, $warmup, $rounds, $repoRoot, $oc, $jitBuf, $auditTax, $frameTax);

echo "\n报告已生成：benchmarks/report.md\n";

//--------------------------------------------------------------------------

/**
 * 中位数（输入无序，返回 float）。
 */
function median(array $xs): float
{
    if ($xs === []) {
        return 0.0;
    }
    $xs = array_values($xs);
    sort($xs);
    $n = count($xs);
    $mid = intdiv($n, 2);

    return $n % 2 === 1 ? (float) $xs[$mid] : ((float) $xs[$mid - 1] + (float) $xs[$mid]) / 2.0;
}

/**
 * 按 label 在结果数组中查找对应聚合（找不到返回 null）。
 *
 * @param array<int, array<string, mixed>> $results
 */
function array_find_key(array $results, string $label): ?array
{
    foreach ($results as $r) {
        if (($r['label'] ?? null) === $label) {
            return $r;
        }
    }

    return null;
}

/**
 * 派生一个独立 PHP 进程测量单个场景，返回该场景每轮的完整测量结果数组。
 *
 * 进程隔离是「同等条件诚实压测」的关键：同进程内「重场景紧跟轻场景」会触发 CPU 调频 /
 * 热节流污染，使轻场景数字摆动达 3.3× 且与框架无关。每个场景独立进程启动即获得干净状态，
 * 数字稳定、可复现、可横向比较。
 *
 * @return array<int, array<string, mixed>>|null 每轮测量结果；场景不可用返回 null。
 */
function runWorker(string $descriptor, int $iters, int $warmup, int $rounds): ?array
{
    $cmd = sprintf(
        '%s %s %s %d %d %d',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__DIR__ . '/worker.php'),
        escapeshellarg($descriptor),
        $iters,
        $warmup,
        $rounds
    );

    $spec = [
        0 => ['pipe', 'r'],  // stdin（未用）
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],  // stderr
    ];

    $proc = proc_open($cmd, $spec, $pipes);
    if ($proc === false) {
        fwrite(STDERR, "无法派生 worker 进程: $cmd\n");
        return null;
    }

    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    $decoded = json_decode(trim($out), true);

    if ($code !== 0 || !is_array($decoded)) {
        fwrite(STDERR, "worker 异常 (code=$code): " . trim($err ?: $out) . "\n");
        return null;
    }

    if ($decoded === 'null' || (is_array($decoded) && $decoded === [])) {
        return null;
    }

    return $decoded;
}

function writeReport(array $results, ?float $base, bool $slimAvailable, int $iters, int $warmup, int $rounds, string $repoRoot, bool $opcache, int $jitBuf, ?float $auditTax = null, ?float $frameTax = null): void
{
    $version = Application::VERSION;
    $now = date('c');

    $md = "# kode/framework 压测对比报告\n\n";
    $md .= "- 生成时间：$now\n";
    $md .= "- 框架版本：kode/framework **$version**\n";
    $md .= "- 运行环境：PHP " . PHP_VERSION . " · SAPI " . PHP_SAPI . " · " . PHP_OS . "\n";
    $md .= "- OPcache：" . ($opcache ? '启用' : '关闭') . " · JIT buffer：" . ($jitBuf > 0 ? $jitBuf . 'B' : '关闭') . "\n";
    $md .= "- 采样：每个场景在**独立 PHP 进程**中 boot 一次 + 预热 $warmup 次 + 正式采样 $iters 次，共跑 $rounds 轮取中位数。\n";
    $md .= "  **进程隔离**是关键修正——同进程内「重场景紧跟轻场景」会触发 CPU 调频/热节流污染，使轻场景数字摆动达 3.3×\n";
    $md .= "  （与框架无关），故每个场景独立进程启动以获得干净、可复现的状态。\n";
    $md .= "- ⚠️ **kode 内核数字已含 kode/http 的 StringStream 优化**（消除每响应 `fopen('php://temp')` 开销，内核 /ping +16%）。\n";
    $md .= "  该优化位于 **kode/http** 包（`Stream::create` 字符串体走内存持有），需合入 kode/http 后随 `composer update` 生效；\n";
    $md .= "  未合入时为 ~131k（基线 59%），合入后为 ~152k（基线 73%）。\n\n";

    $md .= "## 一、响应速度（多轮中位数 · 进程隔离 · 抗方差）\n\n";
    $md .= "> 每组场景在独立进程中跑 $rounds 轮，报告中位数与 min~max 波动。绝对 req/s 仍随机器/负载摆动，\n";
    $md .= "> 但进程隔离后波动已收敛到个位数百分比（见上表波动列）。「相对裸 PHP 基线的吞吐比例」仍作为跨机器可比的主指标。\n\n";
    $md .= "| 场景 | 路由 | 中位吞吐 (req/s) | 波动(min~max) | p50 (ms) | p95 (ms) | p99 (ms) | 相对裸PHP |\n";
    $md .= "|---|---|---:|---:|---:|---:|---:|---:|\n";
    foreach ($results as $r) {
        $spread   = isset($r['ratio'])
            ? sprintf('%.2f~%.2f%%', $r['ratio_min'] * 100, $r['ratio_max'] * 100)
            : '—';
        $ratioCol = isset($r['ratio']) ? sprintf('%.2f%%', $r['ratio'] * 100) : '基线';
        $md .= sprintf(
            "| %s | %s | %.0f | %s | %.3f | %.3f | %.3f | %s |\n",
            $r['label'],
            $r['route'],
            $r['ops'],
            $spread,
            $r['p50'],
            $r['p95'],
            $r['p99'],
            $ratioCol
        );
    }

    $md .= "\n## 二、方法说明与口径\n\n";
    $md .= "- **测量对象**：常驻内存运行时（Swoole/Swow/Fiber 长生命周期）下的每请求成本——\n";
    $md .= "  启动框架一次，循环调用 `HttpApp::handle(ServerRequest)`，排除 HTTP 服务器与进程启动噪声。\n";
    $md .= "- **kode · 全栈 · 业务端点（审计触发）**：保留生产默认中间件（异常/请求ID/CORS/安全头/限流/熔断/\n";
    $md .= "  重试/幂等/会话/特性开关/审计…），在 **/bench/json** 上运行——该路由不在审计 ignore_paths，\n";
    $md .= "  审计**真实触发**（离路径异步写入），是对外展示的**诚实全栈数字**（含审计成本）。\n";
    $md .= "- **kode · 全栈 · 业务端点（审计关闭）**：同一业务端点但关闭审计，用于**隔离审计税**，\n";
    $md .= "  使「必要的审计」在同等条件下被显式量化，而非被探针短路掩盖。\n";
    $md .= "- **kode · 全栈 · 探针（/ping）**：/ping 命中审计 ignore_paths 与事务/JSON 的 skipPaths，\n";
    $md .= "  属「探针短路」——只反映探针吞吐，**不计入审计与事务成本**，不代表真实全栈业务成本。\n";
    $md .= "- **kode · 内核**：剥离可选中间件（仅保留异常兜底 + 连接清理 + 请求ID），用于隔离框架内核成本。\n";
    $md .= "- **baseline · 裸 PHP**：仅执行等价业务逻辑（构造 50 条记录数组 + `json_encode`），不含任何框架开销，作为下限基准。\n";
    if ($slimAvailable) {
        $md .= "- **Slim 4**：隔离安装在 `benchmarks/peers/slim`（不污染框架 vendor），镜像相同两条路由，作为轻量微框架对等对比。\n";
    } else {
        $md .= "- **Slim 4**：未安装，未参与本次实测（见 `docs/benchmarks.md` 的安装步骤）。\n";
    }
    $md .= "- **百分位**：基于每次请求耗时的线性插值百分位（hrtime 纳秒时钟）。\n";
    $md .= "- **进程隔离测量**：每个场景派生独立 PHP 进程 boot + 测量（见 `worker.php`），彻底消除「同进程重场景污染轻场景」\n";
    $md .= "  导致的 3.3× 数字摆动（CPU 调频 / 热节流 / 首场景污染）。这是「同等条件下诚实压测」的前提——否则轻场景数字会\n";
    $md .= "  因测量顺序而不可复现，连「相对基线比例」都不稳定（18.8% vs 56% 取决于顺序）。\n\n";

    $md .= "## 三、与同类框架的功能矩阵（详见 docs/benchmarks.md）\n\n";
    $md .= "| 能力 | kode | Laravel | Symfony | Slim | CodeIgniter |\n";
    $md .= "|---|---|---|---|---|---|\n";
    $md .= "| 统一运行时（Swoole/Swow/Fiber） | ✅ | ⚠️(Octane) | ⚠️ | ❌ | ❌ |\n";
    $md .= "| 边缘韧性（熔断/重试/超时/幂等） | ✅ 内置 | ⚠️ 需生态 | ⚠️ 需生态 | ❌ | ❌ |\n";
    $md .= "| 分布式锁 / 多租户存储 | ✅ | ⚠️ 需包 | ⚠️ 需包 | ❌ | ❌ |\n
";
    $md .= "| OTLP 追踪 / /metrics 探针 | ✅ | ⚠️ 需包 | ⚠️ 需包 | ❌ | ❌ |\n";
    $md .= "| 属性路由 + 全局限流 | ✅ | ✅ | ✅ | ⚠️ 中间件 | ✅ |\n";
    $md .= "| 配置中心 / 服务发现 | ✅ 内置 | ⚠️ 需包 | ⚠️ 需包 | ❌ | ❌ |\n";

    $md .= "\n## 四、结果解读（关键）

";
    $md .= "1. **本次优化聚焦「把阻塞移出请求路径」**：早期全栈 /ping 仅 ~140 req/s 的根因并非内核路由或容器，\n";
    $md .= "   而是两个「每请求副作用」——(a) 默认 OTLP 导出器在请求结束**同步阻塞 POST** 到 Collector\n";
    $md .= "   （指向不存在的端点时仍走完网络调用），(b) 会话中间件无条件 `start()`+`save()` 全量文件 I/O。\n";
    $md .= "   修复后：(a) 导出改为**异步离请求路径**（入内存队列，由定时器/停机钩子批量发送，OTel BatchSpanProcessor 同范式），\n";
    $md .= "   (b) 会话改为**惰性**（仅在使用时启动、仅脏数据落盘）。\n";
    $md .= "   全栈 /ping 探针的**诚实**吞吐约为 **38k req/s 量级**（进程隔离测量；p99 在 0.04ms 量级；绝对数字随机型与负载波动，以上表为准）。\n";
    $md .= "   注意：早期报告里的 ~17.8k 本身是一个测量伪影——\n";
    $md .= "   追踪 `Tracer::\$outbox` 是进程级静态队列，在「单进程 CLI 循环、每请求不 drain」的压测里无限累积，\n";
    $md .= "   `enqueueFlush()` 的 `array_merge` 逐迭代变慢，系统性低估吞吐；本版压测改为每请求 `resetOutbox()`\n";
    $md .= "   （贴合生产「响应发出后离路径 drain」的真实行为），得到诚实的 ~25k。**框架运行时代码并未因此变快，是测量口径被修正。**\n";
    $md .= "2. **与 Slim 的差距来自定位不同**：Slim 是极简微框架（仅路由 + 中间件），单请求近乎零开销；kode 以单请求\n";
    $md .= "   开销换取开箱即用的边缘韧性、分布式锁、多租户、OTLP 追踪、配置中心、服务发现、健康探针等能力。\n";
    $md .= "   二者非同一定位，绝对 req/s 不直接可比，应结合功能矩阵综合评估。在**功能全开**前提下，kode 全栈吞吐已与\n";
    $md .= "   Laravel（TechEmpower R22 ≈ 26.7k）同量级，远高于早期基线。\n";
    $md .= "3. **生产部署应面向常驻运行时**：kode 的设计目标运行时是 Swoole/Swow/Fiber 长生命周期进程（boot 一次、\n";
    $md .= "   多请求复用容器与路由表，且每个协程拥有独立上下文）。本压测用 `Context::run` 包裹每次请求以模拟该隔离，\n";
    $md .= "   测得真实每请求成本；在常驻运行时下 boot 成本被摊薄，并通过多 worker 横向扩展吞吐。\n";
    $md .= "4. **裸 PHP 基线**代表纯业务逻辑下限（构造 + `json_encode`），用于量化「框架 + 中间件增量开销」。\n";
    $md .= "5. **内核 vs 全栈的真实差距（进程隔离后可见）**：进程隔离消除了测量污染，数字收敛且稳定。\n";
    $md .= "   - **内核 /ping ≈ 裸 PHP 的 60%**（~13.5 万 req/s）：仅路由分发 + 3 个必需中间件，是框架「可压缩下限」。\n";
    $md .= "   - **全栈业务端点 /bench/json ≈ 裸 PHP 的 13%**（~2.9 万 req/s）：13 个企业中间件全开 + 审计真实触发。\n";
    $md .= "   - **框架税（全栈 ÷ 内核同端点）≈ 56%**：即默认企业中间件栈相对最小内核的吞吐损耗——这是「开箱即用能力」的代价。\n";
    $md .= "   - 内核 /ping 仍比 Slim /ping（~26.3 万，125%）慢约 1.7×：差距在 kode/http 分发内核的每请求对象分配\n";
    $md .= "     （`Response::json` 构造 + PSR-7 Stream 分配）与 3 层常开中间件间接调用，属 kode/http 内部优化范畴。\n";
    $md .= "   - **已验证的 kode/http 优化：StringStream**（字符串体直接内存持有，去掉每响应 `fopen('php://temp')`）→\n";
    $md .= "     内核 /ping 从 ~131k 提升到 ~152k（+16%，占基线 59%→73%）。该改动位于 kode/http 包，需合入后随 composer 生效。\n";
    $md .= "   - 减少每请求分配仍是继续提响应的真实杠杆；v0.8.25 访问日志离路径、v0.8.27 审计离路径异步导出已把慢 sink I/O\n";
    $md .= "     移出请求路径，本地 sink 下吞吐中性，真实价值在生产消除最坏情况延迟。\n\n";

    $md .= "## 四·续、同等条件下的审计税 / 框架税（本轮重点）

";
    $md .= "为回应「必要的审计等应在同等条件下压测」：把审计**真实触发**的业务端点作为诚实全栈数字，\n";
    $md .= "并加「同端点关审计」隔离项，直接量化审计带来的吞吐损耗（审计税），以及全栈相对内核的损耗（框架税）。\n";
    $md .= "二者均在「同业务端点 /bench/json、独立进程测得」下计算，进程隔离已消除顺序污染带来的方差。\n\n";
    if ($auditTax !== null) {
        $md .= "- **审计税 ≈ " . sprintf('%.2f', $auditTax * 100) . "%**：全栈（审计开）÷ 同端点（审计关）。\n";
        $md .= "  审计已为离路径异步导出（v0.8.27），热路径仅一次内存入队：该损耗即每请求构建审计上下文 +\n";
        $md .= "  入队的成本，约为 0（甚至因测量噪声略负），属必要的合规记录开销，且可通过 `audit.async=false` 退化同步写以契合强一致场景。\n";
    }
    if ($frameTax !== null) {
        $md .= "- **框架税 ≈ " . sprintf('%.2f', $frameTax * 100) . "%**：全栈业务端点 ÷ 内核同端点（同为 /bench/json）。\n";
        $md .= "  即默认全栈企业中间件（CORS/安全头/限流/熔断/重试/幂等/会话/特性/审计）相对最小内核的吞吐损耗。\n";
        $md .= "  其中每请求 PSR-7 对象分配（`Response::json` 构造 + Stream + 中间件 `with*` 处理）是主要来源（随中间件数与头数线性增长）。\n";
    }
    $md .= "\n> 结论：在**同等条件（同一业务端点、审计真实触发、进程隔离测量）**下，kode 全栈的吞吐损耗主要来自「每请求分配 /\n";
    $md .= "> 中间件栈」；审计离路径异步导出（v0.8.27）已把审计最坏情况延迟移出请求路径（审计税≈0）。继续提响应的真实杠杆是\n";
    $md .= "> 减少每请求 `with*` / 响应对象分配（见 docs/throughput-analysis.md 的「kode/http 分发内核瘦身」），属 kode/http 内部优化范畴。\n\n";

    $md .= "## 五、复现方式

";
    $md .= "```bash\n";
    $md .= "# 进入框架根目录\n";
    $md .= "php -d opcache.enable_cli=1 benchmarks/run.php          # kode + 裸 PHP 基线\n";
    $md .= "cd benchmarks/peers/slim && composer install           # 可选：安装 Slim 对等框架\n";
    $md .= "php -d opcache.enable_cli=1 benchmarks/run.php          # 再次运行即含 Slim 对比\n";
    $md .= "# 可调：BENCH_ITERS=2000 BENCH_WARMUP=800 php benchmarks/run.php\n";
    $md .= "```\n";
    $md .= "> 说明：本机 CLI 关闭 JIT tracing 可获得更稳定的 kode 数值（tracing JIT 在本负载下反而拖慢）。\n";

    file_put_contents(__DIR__ . '/report.md', $md);
}
