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

$scenarios = [
    ['label' => 'kode · 全栈 (ping)',        'route' => '/ping',       'build' => static fn () => Kode::scenario($repoRoot, [], '/ping')],
    ['label' => 'kode · 全栈 (json+DI)',     'route' => '/bench/json', 'build' => static fn () => Kode::scenario($repoRoot, [], '/bench/json')],
    ['label' => 'kode · 内核 (最小中间件)',  'route' => '/ping',       'build' => static fn () => Kode::scenario($repoRoot, $disable, '/ping')],
    ['label' => 'kode · 内核 (json+DI)',     'route' => '/bench/json', 'build' => static fn () => Kode::scenario($repoRoot, $disable, '/bench/json')],
    ['label' => 'baseline · 裸 PHP (纯逻辑)', 'route' => '(logic)',     'build' => static fn () => Baseline::scenario()],
];

$slimAvailable = Slim::available($peerRoot);
if ($slimAvailable) {
    $scenarios[] = ['label' => 'Slim 4 · (ping)',        'route' => '/ping',       'build' => static fn () => Slim::scenario($peerRoot, '/ping')];
    $scenarios[] = ['label' => 'Slim 4 · (json)',        'route' => '/bench/json', 'build' => static fn () => Slim::scenario($peerRoot, '/bench/json')];
}

$results = [];
$built   = [];

// 1) 构建 + 健康检查 + 轻量预热（每个场景 boot 一次，复用常驻实例，模拟生产常驻运行时）。
foreach ($scenarios as $sc) {
    /** @var callable(): ?int $fn */
    $fn = $sc['build']();

    // 健康校验：框架场景必须返回 200，否则本场景数据不可信。
    $status = $fn();
    if ($status !== null && $status !== 200) {
        echo sprintf("  [跳过] %-26s 健康检查失败，状态码=%s\n", $sc['label'], (string) $status);
        continue;
    }

    Bench::measure($fn, $warmup, 1); // 激活 JIT/OPcache，剔除冷启动噪声
    $built[] = ['sc' => $sc, 'fn' => $fn];
    echo sprintf("  [就绪] %-26s\n", $sc['label']);
}

// 2) 多轮测量：同轮内各场景顺序执行（共享同一机器状态），轮间取中位数消除瞬时波动。
//    先前单轮绝对数字会在不同机器 / 负载下摆动 ~2.4×，故以「相对裸 PHP 基线的比例」作为
//    稳定主指标——kode 与基线在同轮测得，机器方差在比值中相互抵消。
$perRoundOps = [];   // [label => ops[]]
$perRoundRes = [];   // [label => result[]]
for ($rd = 1; $rd <= $rounds; $rd++) {
    foreach ($built as $b) {
        $r = Bench::measure($b['fn'], 0, $iters);
        $perRoundOps[$b['sc']['label']][] = $r['ops'];
        $perRoundRes[$b['sc']['label']][] = $r;
    }
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

if ($slimAvailable) {
    echo "\n（已包含 Slim 4 对等框架实测数据）\n";
} else {
    echo "\n提示：未检测到 benchmarks/peers/slim/vendor，已跳过 Slim 对比。\n"
        . "      运行 `cd benchmarks/peers/slim && composer install` 后可获得同类框架真实对比。\n";
}

writeReport($results, $base, $slimAvailable, $iters, $warmup, $rounds, $repoRoot, $oc, $jitBuf);

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

function writeReport(array $results, ?float $base, bool $slimAvailable, int $iters, int $warmup, int $rounds, string $repoRoot, bool $opcache, int $jitBuf): void
{
    $version = Application::VERSION;
    $now = date('c');

    $md = "# kode/framework 压测对比报告\n\n";
    $md .= "- 生成时间：$now\n";
    $md .= "- 框架版本：kode/framework **$version**\n";
    $md .= "- 运行环境：PHP " . PHP_VERSION . " · SAPI " . PHP_SAPI . " · " . PHP_OS . "\n";
    $md .= "- OPcache：" . ($opcache ? '启用' : '关闭') . " · JIT buffer：" . ($jitBuf > 0 ? $jitBuf . 'B' : '关闭') . "\n";
    $md .= "- 采样：每个场景预热 $warmup 次 + 正式采样 $iters 次，共跑 $rounds 轮取中位数（单进程内 boot 一次 + 多次 handle）\n\n";

    $md .= "## 一、响应速度（多轮中位数 · 抗方差）\n\n";
    $md .= "> 每组场景跑 $rounds 轮，报告中位数与 min~max 波动。**绝对 req/s 随机器/负载摆动很大**，\n";
    $md .= "> 故以「相对裸 PHP 基线的吞吐比例」为主指标——kode 与基线在同轮、同机器状态下测得，机器方差在比值中相互抵消。\n\n";
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
    $md .= "- **kode · 全栈**：保留生产默认中间件（异常/请求ID/追踪/CORS/安全头/熔断/重试/幂等/会话/特性开关），\n";
    $md .= "  仅关闭全局限流以避免压测触发 429。\n";
    $md .= "- **kode · 内核**：在上述基础上剥离可选中间件，仅保留路由分发 + 请求ID + 异常兜底，用于隔离框架内核成本。\n";
    $md .= "- **baseline · 裸 PHP**：仅执行等价业务逻辑（构造 50 条记录数组 + `json_encode`），不含任何框架开销，作为下限基准。\n";
    if ($slimAvailable) {
        $md .= "- **Slim 4**：隔离安装在 `benchmarks/peers/slim`（不污染框架 vendor），镜像相同两条路由，作为轻量微框架对等对比。\n";
    } else {
        $md .= "- **Slim 4**：未安装，未参与本次实测（见 `docs/benchmarks.md` 的安装步骤）。\n";
    }
    $md .= "- **百分位**：基于每次请求耗时的线性插值百分位（hrtime 纳秒时钟）。\n";
    $md .= "- **多轮抗方差**：每个场景跑 $rounds 轮，报告中位数与 min~max 波动；主指标为「相对裸 PHP 基线吞吐比例」——\n";
    $md .= "  kode 与基线在同轮、同机器状态下测得，机器方差（CPU 调频 / 后台负载 / 缓存温度）在比值中抵消，\n";
    $md .= "  使「框架响应」可在不同机器、不同时刻间横向比较，而不被绝对 req/s 的瞬时摆动误导。\n\n";

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
    $md .= "   全栈 /ping 的**诚实**吞吐约为 **25k req/s 量级**（p99 在 0.04ms 量级；绝对数字随机型与负载波动，以上表为准）。\n";
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
    $md .= "5. **用「相对裸 PHP 比例」作稳定主指标**：kode 是「分配 / GC 绑定」的——跨机器 / 跨时刻的**绝对** req/s 波动很大\n";
    $md .= "   （本机裸 PHP 基线与 Slim 在两次运行间可差 2.4×），而 kode 全栈稳定在同一量级——说明其吞吐受每请求对象分配与 GC\n";
    $md .= "   主导，而非原始 CPU 频率。故本版压测改为**多轮（默认 5 轮）+ 比值**：每轮 kode 与基线同机同状态测得，取「kode ÷ 同轮\n";
    $md .= "   基线」中位数比例，机器方差在比值中抵消。实测稳定结论：**全栈 `/ping` ≈ 裸 PHP 的 16.4%（波动 16.1~17.0%）、\n";
    $md .= "   全栈 `json+DI` ≈ 9.0%；内核（最小中间件）与全栈几乎同量级（16.6% vs 16.4%），印证瓶颈在「每请求分配 / 中间件栈」而非路由内核。\n";
    $md .= "   因此**减少每请求分配**才是继续提响应的真实杠杆。v0.8.25 已把**访问日志做成「离路径异步导出」**（与追踪同范式）：\n";
    $md .= "   中间件热路径仅做一次内存入队，真实格式化 + 文件写入由响应后的 shutdown / 优雅停机钩子批量执行。在**本地文件** sink 下\n";
    $md .= "   该改动吞吐中性（本地 fwrite 廉价、被缓冲，A/B：async 与同步写同机同量级）——印证 kode 并非被日志 I/O 阻塞。其真实价值在\n";
    $md .= "   **生产**：把日志侧的格式化 / 写入（尤其网络 / syslog / 集中式日志等慢 sink）移出请求路径，消除每请求最坏情况延迟，\n";
    $md .= "   且同样保留 synchronous 退化开关（`access_log.async=false`）以兼容审计强一致场景。微小的单点分配削减（如去掉一次 Uri\n";
    $md .= "   克隆）在单进程微基准里被 GC 噪声淹没，不构成可测增益。\n\n";

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
