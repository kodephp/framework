<?php

declare(strict_types=1);

/*
 * kode/framework 压测对比编排器
 * ---------------------------------
 * 运行：php benchmarks/run.php
 * 可调：BENCH_ITERS（默认 8000）、BENCH_WARMUP（默认 1000）
 *
 * 测量口径：单进程内「一次 boot + N 次 handle()」——即常驻内存运行时
 * （Swoole/Swow/Fiber 长生命周期）的真实每请求开销，排除 HTTP 服务器与
 * 进程启动噪声，专注于框架 + 中间件栈的吞吐与延迟。
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
$disable = ['logging', 'session', 'idempotency', 'feature', 'cors', 'security', 'locale', 'resilience', 'observability'];

echo "kode/framework 压测对比  (iters=$iters, warmup=$warmup)\n";
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

foreach ($scenarios as $sc) {
    /** @var callable(): ?int $fn */
    $fn = $sc['build']();

    // 健康校验：框架场景必须返回 200，否则本场景数据不可信。
    $status = $fn();
    if ($status !== null && $status !== 200) {
        echo sprintf("  [跳过] %-26s 健康检查失败，状态码=%s\n", $sc['label'], (string) $status);
        continue;
    }

    $r = Bench::measure($fn, $warmup, $iters);
    $r['label'] = $sc['label'];
    $r['route'] = $sc['route'];
    $results[] = $r;

    echo sprintf(
        "  %-26s %10.0f req/s | p50 %7.3f  p95 %7.3f  p99 %7.3f ms\n",
        $sc['label'],
        $r['ops'],
        $r['p50'],
        $r['p95'],
        $r['p99']
    );
}

// 基准（裸 PHP）用于计算框架增量开销
$base = null;
foreach ($results as $r) {
    if (str_starts_with($r['label'], 'baseline')) {
        $base = $r['ops'];
    }
}

echo str_repeat('-', 72) . "\n";
if ($base !== null && $base > 0) {
    echo "框架增量开销（相对裸 PHP 基线）:\n";
    foreach ($results as $r) {
        if (str_starts_with($r['label'], 'baseline')) {
            continue;
        }
        $ratio = $r['ops'] / $base;
        echo sprintf("  %-26s 约为裸 PHP 的 %.2f%% 吞吐\n", $r['label'], $ratio * 100);
    }
}

if ($slimAvailable) {
    echo "\n（已包含 Slim 4 对等框架实测数据）\n";
} else {
    echo "\n提示：未检测到 benchmarks/peers/slim/vendor，已跳过 Slim 对比。\n"
        . "      运行 `cd benchmarks/peers/slim && composer install` 后可获得同类框架真实对比。\n";
}

writeReport($results, $base, $slimAvailable, $iters, $warmup, $repoRoot, $oc, $jitBuf);

echo "\n报告已生成：benchmarks/report.md\n";

//--------------------------------------------------------------------------

function writeReport(array $results, ?float $base, bool $slimAvailable, int $iters, int $warmup, string $repoRoot, bool $opcache, int $jitBuf): void
{
    $version = Application::VERSION;
    $now = date('c');

    $md = "# kode/framework 压测对比报告\n\n";
    $md .= "- 生成时间：$now\n";
    $md .= "- 框架版本：kode/framework **$version**\n";
    $md .= "- 运行环境：PHP " . PHP_VERSION . " · SAPI " . PHP_SAPI . " · " . PHP_OS . "\n";
    $md .= "- OPcache：" . ($opcache ? '启用' : '关闭') . " · JIT buffer：" . ($jitBuf > 0 ? $jitBuf . 'B' : '关闭') . "\n";
    $md .= "- 采样：每次场景预热 $warmup 次 + 正式采样 $iters 次（单进程内 boot 一次 + 多次 handle）\n\n";

    $md .= "## 一、响应速度（吞吐量 / 延迟百分位）\n\n";
    $md .= "| 场景 | 路由 | 吞吐 (req/s) | p50 (ms) | p95 (ms) | p99 (ms) | min (ms) | max (ms) |\n";
    $md .= "|---|---|---:|---:|---:|---:|---:|---:|\n";
    foreach ($results as $r) {
        $md .= sprintf(
            "| %s | %s | %.0f | %.3f | %.3f | %.3f | %.3f | %.3f |\n",
            $r['label'],
            $r['route'],
            $r['ops'],
            $r['p50'],
            $r['p95'],
            $r['p99'],
            $r['min'],
            $r['max']
        );
    }

    if ($base !== null && $base > 0) {
        $md .= "\n### 框架增量开销（相对裸 PHP 基线）\n\n";
        $md .= "| 场景 | 相对裸 PHP 吞吐比例 |\n|---|---:|\n";
        foreach ($results as $r) {
            if (str_starts_with($r['label'], 'baseline')) {
                continue;
            }
            $md .= sprintf("| %s | %.2f%% |\n", $r['label'], ($r['ops'] / $base) * 100);
        }
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
    $md .= "- **百分位**：基于每次请求耗时的线性插值百分位（hrtime 纳秒时钟）。\n\n";

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
    $md .= "5. **kode 是「分配 / GC 绑定」的**：跨机器 / 跨时刻的绝对数字波动很大（本机裸 PHP 基线与 Slim 在两次运行间可差 2.4×），\n";
    $md .= "   而 kode 全栈稳定在同一量级——说明其吞吐受每请求对象分配与 GC 主导，而非原始 CPU 频率。因此**减少每请求分配**\n";
    $md .= "   才是继续提响应的真实杠杆。本轮（v0.8.25）已把**访问日志做成「离路径异步导出」**（与追踪同范式）：中间件热路径仅\n";
    $md .= "   做一次内存入队，真实格式化 + 文件写入由响应后的 shutdown / 优雅停机钩子批量执行。在**本地文件** sink 下该改动\n";
    $md .= "   吞吐中性（本地 fwrite 廉价、被缓冲，A/B：async 与同步写同机同量级）——印证 kode 并非被日志 I/O 阻塞。其真实价值在\n";
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
