<?php

declare(strict_types=1);

/**
 * kode 全频谱对标服务器。
 *
 * **重要（审计修正）**：本脚本不再自建 `Swoole\Http\Server`，而是委托
 * `Kode::serve()`（kode/process 运行时，自动择优 Swoole/Workerman/Native 后端），
 * 请求/响应经 `HttpBridge` 与 kode/http 的 PSR-7 内核互转——与框架
 * `bin/kode serve` 实际交付路径**完全一致**。压测数字即真实生产数字，
 * 不再因「自建 Swoole 适配器」而偏离框架实际运行时。
 *
 * 频谱：/ping(hello world) → /bench/json(内存) → 10 个「真实 DB 业务查询」端点
 *       = {raw PDO, kode 原生查询构造器, Eloquent, Doctrine DBAL, ThinkPHP} × {MySQL, pgsql}
 *
 * 每个 worker 在 WorkerStart 独立 boot kode + 初始化各 ORM 连接（连接池/静态复用），
 * 高并发下不共享可变状态。所有 ORM 经各自原生读 API 执行「一次主键 SELECT + 返回 JSON」，
 * 以公平对比「框架 + 各 ORM + 各 DB」的真实吞吐。
 *
 * 运行：php benchmarks/peers/kode_swoole_server.php
 * 端口：BENCH_PORT（默认 8093）；worker：BENCH_WORKERS（默认 8）
 * 档位（框架默认 opt-in：以下能力 config 默认全 false，开发者按需开启）：
 *   KODE_PROFILE=off|default|lean -> 零跨切面中间件（最小内核，压测基线）
 *   KODE_PROFILE=full             -> 全部开启（cors/security/locale/resilience/logging/observability/session/idempotency/feature）
 *   KODE_ENABLE=组名(逗号)         -> 在 off 基础上增量开启指定组（定位每项边际成本）
 *   KODE_DISABLE=组名(逗号)        -> 在 full/on 基础上强制关闭指定组
 */

require __DIR__ . '/../../vendor/autoload.php';

// 多 ORM 依赖（illuminate/database, doctrine/dbal, topthink/think-orm），独立 harness 不污染框架 composer.json。
$harnessAutoload = __DIR__ . '/../orm-harness/vendor/autoload.php';
if (is_file($harnessAutoload)) {
    require $harnessAutoload;
}

use Kode\Framework\Application;
use Kode\Http\App;
use Kode\Framework\Http\Resp;
use Kode\Process\Http\Request as ProcessRequest;
use Kode\Process\Kode;
use Kode\Process\Runtime\ConnectionInterface;
use Kode\Framework\Server\HttpBridge;
use Kode\Framework\Server\GracefulShutdown;

$repoRoot = dirname(__DIR__, 2);

$profile = $_SERVER['KODE_PROFILE'] ?? 'off';   // off(default) | full | lean(alias off)
$lean = ($_SERVER['KODE_LEAN'] ?? '0') === '1';   // 1=绕过 PSR-7 内核路径（toPsr7+handle+emit），直发 raw，定位「桥接」边际成本
$enableList = [];
if (!empty($_SERVER['KODE_ENABLE'])) {
    $enableList = array_filter(array_map('trim', explode(',', (string) $_SERVER['KODE_ENABLE'])));
}
$disableList = [];
if (!empty($_SERVER['KODE_DISABLE'])) {
    $disableList = array_filter(array_map('trim', explode(',', (string) $_SERVER['KODE_DISABLE'])));
}

// 全部可选跨切面能力组（框架 config 默认均为 false / opt-in）。
$allGroups = [
    'cors'          => "<?php return ['enabled' => true];\n",
    'security'      => "<?php return ['enabled' => true, 'audit' => ['enabled' => true], 'request_id' => true];\n",
    'locale'        => "<?php return ['enabled' => true];\n",
    'resilience'    => "<?php return ['enabled' => true, 'breaker' => ['http' => ['enabled' => true]], 'retry' => ['http' => ['enabled' => true]]];\n",
    'logging'       => "<?php return ['access_log' => ['enabled' => true]];\n",
    'observability' => "<?php return ['metrics' => ['enabled' => true], 'tracing' => ['enabled' => true]];\n",
    'session'       => "<?php return ['enabled' => true];\n",
    'idempotency'   => "<?php return ['http' => ['enabled' => true]];\n",
    'feature'       => "<?php return ['enabled' => true];\n",
];

// 决定哪些组「开」：off/lean/default/空 -> 全关；full -> 全开；否则取 KODE_ENABLE 并集。
if ($profile === 'full') {
    $onGroups = array_keys($allGroups);
} elseif (in_array($profile, ['off', 'lean', 'default', ''], true)) {
    $onGroups = [];
} else {
    $onGroups = [];
}
if ($enableList !== []) {
    $onGroups = array_values(array_unique([...$onGroups, ...$enableList]));
}
$onGroups = array_values(array_diff($onGroups, $disableList));

// limiting 在压测中恒关（需 redis 才显效，且非本对比项）。
$tmp = sys_get_temp_dir() . '/kode-peer-' . substr(md5($repoRoot . '|' . $profile . '|' . implode(',', $onGroups)), 0, 10);
if (!is_dir($tmp . '/config')) {
    mkdir($tmp . '/config', 0o777, true);
    foreach (glob($repoRoot . '/config/*.php') ?: [] as $file) {
        copy($file, $tmp . '/config/' . basename($file));
    }
    putenv('SESSION_DRIVER=array');
    $_ENV['SESSION_DRIVER'] = 'array';
    foreach ($allGroups as $g => $onSnippet) {
        $enabled = in_array($g, $onGroups, true);
        if ($enabled) {
            file_put_contents($tmp . '/config/' . $g . '.php', $onSnippet);
        } else {
            file_put_contents($tmp . '/config/' . $g . '.php', match ($g) {
                'logging'        => "<?php return ['access_log' => ['enabled' => false]];\n",
                'session'        => "<?php return ['enabled' => false];\n",
                'idempotency'    => "<?php return ['http' => ['enabled' => false]];\n",
                'feature'        => "<?php return ['enabled' => false];\n",
                'cors'           => "<?php return ['enabled' => false];\n",
                'security'       => "<?php return ['enabled' => false, 'audit' => ['enabled' => false], 'request_id' => false];\n",
                'locale'         => "<?php return ['enabled' => false];\n",
                'resilience'     => "<?php return ['enabled' => false, 'breaker' => ['http' => ['enabled' => false]], 'retry' => ['http' => ['enabled' => false]]];\n",
                'observability'  => "<?php return ['metrics' => ['enabled' => false], 'tracing' => ['enabled' => false]];\n",
                default          => "<?php return ['enabled' => false];\n",
            });
        }
    }
    file_put_contents($tmp . '/config/limiting.php', "<?php return ['enabled' => false];\n");
}

// 对标 parity（重要）：webman WEBMAN_MW=on 仅挂 4 个跨切面中间件
// （CORS + Security头 + 链路ID + 访问日志），**不含审计**。kode 的 audit 默认开启
// 且 AuditMiddleware 会被 pipe 进管线（/ping 在 ignore_paths 内跳过，但 /bench/json、
// /bench/db 不跳过），故 kode ON 若带 audit 即比 webman ON「多一个中间件」，能力集不对等。
// KODE_AUDIT=off 时把 config/audit.php 覆写为禁用，使 kode ON 的能力集精确等于 webman ON，
// 做到「开启的一模一样」。（不影响 run.sh 能力梯度：梯度 harness 不传此变量，audit 走仓库默认。）
$auditOff = (($_SERVER['KODE_AUDIT'] ?? getenv('KODE_AUDIT') ?? 'on') === 'off');
if ($auditOff) {
    file_put_contents($tmp . '/config/audit.php', "<?php return ['enabled' => false];\n");
}


$port = (int) ($_SERVER['BENCH_PORT'] ?? 8093);
$workers = (int) ($_SERVER['BENCH_WORKERS'] ?? 8);

// 双库连接参数（MySQL root/root，pgsql root trust）。
$mysqlCred = ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'kode_bench', 'username' => 'root', 'password' => 'root'];
$pgCred = ['host' => '127.0.0.1', 'port' => 5432, 'database' => 'kode_bench', 'username' => 'root', 'password' => ''];

$http = null;
$app = null;
$graceful = null;

$bootWorker = static function () use ($tmp, &$http, &$app, &$graceful, $mysqlCred, $pgCred): void {
    if ($http !== null) {
        return;
    }

    $app = Application::make($tmp);
    /** @var App $http */
    $http = $app->http();

    // ---------- hello world / 内存锚点 ----------
    $http->get('/ping', static fn () => Resp::json(['status' => 'ok']));
    $http->get('/bench/json', static function () {
        // 与 webman 对标 handler 完全同构：array_map(range) + framework/now 字段
        $items = array_map(
            static fn (int $i) => ['id' => $i, 'name' => 'item-' . $i],
            range(1, 50)
        );

        return Resp::json([
            'framework' => 'kode',
            'now'       => date('c'),
            'items'     => $items,
        ]);
    });

    // ---------- kode 原生查询构造器连接（driver=pdo 执行器） ----------
    \Kode\Database\Db\Db::addConnection('kode-mysql', [
        'driver' => 'pdo', 'database_driver' => 'mysql',
        'host' => $mysqlCred['host'], 'port' => $mysqlCred['port'], 'database' => $mysqlCred['database'],
        'username' => $mysqlCred['username'], 'password' => $mysqlCred['password'], 'charset' => 'utf8mb4',
    ]);
    \Kode\Database\Db\Db::addConnection('kode-pgsql', [
        'driver' => 'pdo', 'database_driver' => 'pgsql',
        'host' => $pgCred['host'], 'port' => $pgCred['port'], 'database' => $pgCred['database'],
        'username' => $pgCred['username'], 'password' => $pgCred['password'], 'charset' => 'utf8',
    ]);

    // ---------- Eloquent（illuminate/database）：WorkerStart 初始化 Capsule 连接池 ----------
    if (class_exists(\Illuminate\Database\Capsule\Manager::class)) {
        $capsule = new \Illuminate\Database\Capsule\Manager();
        $capsule->addConnection([
            'driver' => 'mysql', 'host' => $mysqlCred['host'], 'port' => $mysqlCred['port'],
            'database' => $mysqlCred['database'], 'username' => $mysqlCred['username'], 'password' => $mysqlCred['password'],
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
        ], 'mysql');
        $capsule->addConnection([
            'driver' => 'pgsql', 'host' => $pgCred['host'], 'port' => $pgCred['port'],
            'database' => $pgCred['database'], 'username' => $pgCred['username'], 'password' => $pgCred['password'],
            'charset' => 'utf8', 'prefix' => '',
        ], 'pgsql');
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }

    // ---------- ThinkPHP ORM（topthink/think-orm v3）：先注册命名连接，再按名 connect ----------
    if (class_exists(\think\facade\Db::class)) {
        \think\facade\Db::setConfig([
            'connections' => [
                'bench_mysql' => [
                    'type' => 'mysql', 'hostname' => $mysqlCred['host'], 'hostport' => $mysqlCred['port'],
                    'database' => $mysqlCred['database'], 'username' => $mysqlCred['username'],
                    'password' => $mysqlCred['password'], 'charset' => 'utf8mb4', 'prefix' => '',
                ],
                'bench_pgsql' => [
                    'type' => 'pgsql', 'hostname' => $pgCred['host'], 'hostport' => $pgCred['port'],
                    'database' => $pgCred['database'], 'username' => $pgCred['username'],
                    'password' => $pgCred['password'], 'charset' => 'utf8mb4', 'prefix' => '',
                ],
            ],
        ]);
    }

    // ---------- 路由表：layer × db ----------
    $layers = [
        'raw'      => '原生 PDO',
        'kode'     => 'kode/database 查询构造器',
        'eloquent' => 'Laravel Eloquent',
        'doctrine' => 'Doctrine DBAL',
        'think'    => 'ThinkPHP ORM',
    ];
    $dbs = ['mysql' => $mysqlCred, 'pgsql' => $pgCred];

    foreach ($layers as $layer => $label) {
        foreach ($dbs as $db => $cred) {
            $http->get("/bench/$layer/$db", static function () use ($layer, $db, $cred) {
                $id = random_int(1, 1000);
                $t0 = hrtime(true);
                $row = null;

                if ($layer === 'raw') {
                    static $rawPool = [];
                    static $rawStmt = [];
                    $key = "raw-$db";
                    if (!isset($rawPool[$key])) {
                        $dsn = $db === 'pgsql'
                            ? "pgsql:host={$cred['host']};port={$cred['port']};dbname={$cred['database']}"
                            : "mysql:host={$cred['host']};port={$cred['port']};dbname={$cred['database']};charset=utf8mb4";
                        $p = new \PDO($dsn, $cred['username'], $cred['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                        $rawPool[$key] = $p;
                        // 生产实践：prepared statement 按 worker 缓存复用，避免每请求重复 prepare。
                        $rawStmt[$key] = $p->prepare('SELECT * FROM bench_users WHERE id = ?');
                    }
                    $rawStmt[$key]->execute([$id]);
                    $row = $rawStmt[$key]->fetch(\PDO::FETCH_ASSOC);
                } elseif ($layer === 'kode') {
                    $conn = $db === 'pgsql' ? 'kode-pgsql' : 'kode-mysql';
                    $row = \Kode\Database\Db\Db::connection($conn)
                        ->table('bench_users')->where('id', $id)->first();
                } elseif ($layer === 'eloquent') {
                    $row = \Illuminate\Database\Capsule\Manager::connection($db)
                        ->table('bench_users')->where('id', $id)->first();
                    $row = $row ? (array) $row : null;
                } elseif ($layer === 'doctrine') {
                    static $doctrinePool = [];
                    $key = "doctrine-$db";
                    if (!isset($doctrinePool[$key])) {
                        $map = ['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql'];
                        $doctrinePool[$key] = \Doctrine\DBAL\DriverManager::getConnection([
                            'driver' => $map[$db], 'host' => $cred['host'], 'port' => $cred['port'],
                            'dbname' => $cred['database'], 'user' => $cred['username'], 'password' => $cred['password'],
                            'charset' => $db === 'pgsql' ? 'utf8' : 'utf8mb4',
                        ]);
                    }
                    $row = $doctrinePool[$key]->fetchAssociative('SELECT * FROM bench_users WHERE id = ?', [$id]);
                } elseif ($layer === 'think') {
                    $row = \think\facade\Db::connect('bench_' . $db)
                        ->table('bench_users')->where('id', $id)->find();
                }

                $us = (hrtime(true) - $t0) / 1000;

                return Resp::json([
                    'layer' => $layer, 'db' => $db, 'user' => $row,
                    'query_us' => (int) $us,
                ]);
            });
        }
    }

    /** @var GracefulShutdown|null $graceful */
    $graceful = $app->core()->container->get(GracefulShutdown::class);
};

// 运行时选择：默认 auto（Swoole 可用时优先）；可经 KODE_RUNTIME=swoole|workerman|native 强制，
// 用于压测中做「同类运行时」对比（如 kode·lean@Workerman 对齐 webman@Workerman，隔离运行时差异）。
$runtimeArg = $_SERVER['KODE_RUNTIME'] ?? 'auto';
$runtimeArg = $runtimeArg === 'auto' ? null : $runtimeArg;

// KODE_LEAN=1：绕过 PSR-7 内核路径的 raw 直发路由表（与内核注册的同构 handler，仅去掉 PSR-7 包装）。
// 用于量化「桥接层（HttpBridge::toPsr7 + kode/http App::handle + HttpBridge::emit）」的边际成本。
$leanRoutes = $lean ? [
    '/ping' => static fn () => ['status' => 'ok'],
    '/bench/json' => static function () {
        $items = array_map(
            static fn (int $i) => ['id' => $i, 'name' => 'item-' . $i],
            range(1, 50)
        );

        return ['framework' => 'kode', 'now' => date('c'), 'items' => $items];
    },
] : [];

echo "kode peer server on :$port (workers=$workers, profile=$profile, onGroups=[" . implode(',', $onGroups) . "], runtime=" . ($runtimeArg ?? 'auto') . ($lean ? ', LEAN' : '') . ")\n";
echo "routes: /ping /bench/json /bench/{raw,kode,eloquent,doctrine,think}/{mysql,pgsql}\n";

Kode::serve("http://127.0.0.1:$port", [
    'workers' => $workers,
    'name'    => 'kode-http',
], $runtimeArg)
    ->on('workerStart', static function (int $workerId) use ($bootWorker): void {
        $bootWorker();
    })
    ->on('message', static function (ConnectionInterface $conn, $message) use (&$http, &$graceful, $bootWorker, $lean, $leanRoutes): void {
        if (!$message instanceof ProcessRequest) {
            return;
        }

        $bootWorker();
        if ($http === null) {
            HttpBridge::emit($conn, Resp::error('服务尚未就绪', 503));

            return;
        }

        // 绕过 PSR-7 内核路径：直发 raw（定位「桥接层」边际成本）。
        if ($lean && isset($leanRoutes[$path = $message->path()])) {
            $data = $leanRoutes[$path]();
            $body = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);
            $body = (string) $body;
            $raw = "HTTP/1.1 200 OK\r\n"
                . "Content-Type: application/json; charset=utf-8\r\n"
                . "Content-Length: " . strlen($body) . "\r\n"
                . "Connection: keep-alive\r\n\r\n" . $body;
            $conn->send($raw, true);

            return;
        }

        try {
            $psr = HttpBridge::toPsr7($message);
            $handler = static fn () => $http->handle($psr);
            $response = $graceful instanceof GracefulShutdown
                ? $graceful->track($handler)
                : $handler();
            $protocol = preg_match('#HTTP/(\d+\.\d+)#i', $message->protocol(), $m) ? $m[1] : '1.1';
            HttpBridge::emit($conn, $response, $protocol);
        } catch (\Throwable $e) {
            HttpBridge::emit($conn, Resp::error('服务器内部错误', 500));
        }
    })
    ->start();
