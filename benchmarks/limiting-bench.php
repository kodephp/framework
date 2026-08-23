<?php

declare(strict_types=1);

/**
 * 限流链路微基准（沙箱 PHP 8.3.32 / kode/limiting 2.1.0 + 框架 v0.8.42）。
 *
 * 用法: php benchmarks/limiting-bench.php [iterations] [drivers]
 *   iterations 默认 200_000；drivers 逗号分隔，默认 "middleware,memory,pdo"
 *   可选: middleware|memory|pdo|memory-unique|pdo-unique
 *
 * 口径：预热 n/10 次后计时，重复 3 轮取最小（热状态最稳，避免 GC 尖峰计入）。
 * 目的是「相对热路径成本」测量，不是跨机绝对吞吐——绝对值受本机 CPU 影响。
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Framework\Http\Middleware\RateLimitMiddleware;
use Kode\Framework\Http\RateLimit\LimiterFactory;
use Kode\Framework\Http\RouteRegistry;
use Kode\Http\Response;
use Kode\Http\Routing\Router;
use Kode\Limiting\Attribute\RateLimit;
use Kode\Limiting\Enum\LimiterType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\ServerRequest;

final class PassHandler implements RequestHandlerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, [], 'ok');
    }
}

function bench(string $name, callable $fn, int $n): void
{
    // 预热
    for ($i = 0; $i < (int) ($n / 10); ++$i) {
        $fn();
    }

    $best = PHP_FLOAT_MAX;
    $rounds = 3;
    for ($r = 0; $r < $rounds; ++$r) {
        $t0 = hrtime(true);
        for ($i = 0; $i < $n; ++$i) {
            $fn();
        }
        $el = hrtime(true) - $t0;
        $best = min($best, $el);
    }

    $perOpNs = $best / $n;
    $ops = $n / ($best / 1e9);
    printf("%-26s %12.0f ops/s  %8.1f ns/op\n", $name, $ops, $perOpNs);
}

$n = isset($argv[1]) ? max(10_000, (int) $argv[1]) : 200_000;
$drivers = isset($argv[2]) ? explode(',', $argv[2]) : ['middleware', 'memory', 'pdo'];

// --- 固定装配：与 RateLimitTest 同构 ---
$router = new Router();
$registry = new RouteRegistry();
$factory = new LimiterFactory(['driver' => 'memory', 'capacity' => 10_000, 'rate' => 1000.0]);

$rule = new RateLimit(capacity: 100_000, rate: 10_000.0, type: LimiterType::TOKEN_BUCKET);
$route = $router->add('GET', '/limited', fn() => new Response(200));
$registry->tagRateLimits($route, [$rule]);

$mwMemory = new RateLimitMiddleware($router, $registry, $factory, [
    'enabled' => true, 'driver' => 'memory', 'capacity' => 10_000, 'rate' => 1000.0,
]);

// 无规则路由（全局兜底关）→ 早退路径
$router2 = new Router();
$registry2 = new RouteRegistry();
$route2 = $router2->add('GET', '/open', fn() => new Response(200));
$mwOpen = new RateLimitMiddleware($router2, $registry2, new LimiterFactory(['driver' => 'memory']), [
    'enabled' => true, 'driver' => 'memory', 'capacity' => 10_000, 'rate' => 1000.0,
]);

$handler = new PassHandler();
$req = new ServerRequest('GET', '/limited');
$reqOpen = new ServerRequest('GET', '/open');
$reqA = new ServerRequest('GET', '/limited', [], 'b', '1.1', ['REMOTE_ADDR' => '10.0.0.1']);

// pdo sqlite memory
$pdoFactory = new LimiterFactory([
    'driver' => 'pdo',
    'pdo' => ['dsn' => 'sqlite::memory:', 'table' => 'limiting'],
]);
try {
    $pdoLimiter = $pdoFactory->make($rule);
    $pdoReady = true;
} catch (Throwable $e) {
    fwrite(STDERR, 'pdo driver 初始化失败: ' . $e->getMessage() . "\n");
    $pdoReady = false;
}

// redis（需 redis-server 在 127.0.0.1:6379 运行；无服务时自动跳过）
$redisFactory = new LimiterFactory([
    'driver' => 'redis',
    'redis' => ['mode' => 'standalone', 'host' => '127.0.0.1', 'port' => 6379, 'prefix' => 'bench:'],
]);
$mwRedis = new RateLimitMiddleware($router, $registry, $redisFactory, [
    'enabled' => true, 'driver' => 'redis',
    'redis' => ['mode' => 'standalone', 'host' => '127.0.0.1', 'port' => 6379, 'prefix' => 'bench:'],
]);
try {
    $redisLimiter = $redisFactory->make($rule);
    $redisLimiter->consume('bench:probe', 1);
    $redisReady = true;
} catch (Throwable $e) {
    fwrite(STDERR, 'redis 不可用（' . $e->getMessage() . '），跳过 redis 场景' . "\n");
    $redisReady = false;
}

$memoryLimiter = $factory->make($rule);

echo "PHP " . PHP_VERSION . " / kode/limiting 2.2.0 / iterations={$n}\n";
echo "------------------------------------------------------------------\n";

foreach ($drivers as $d) {
    switch ($d) {
        case 'middleware':
            // 完整中间件链：路由解析 + 规则读取 + signature + consume + 头部注入
            bench('mw/limited (rule)', fn() => $mwMemory->process($req, $handler), $n);
            bench('mw/open (no-rule)', fn() => $mwOpen->process($reqOpen, $handler), $n);
            break;

        case 'memory':
            // 纯限流核心：固定 key（同 IP 同路由连续请求 = 热键）
            bench('memory/consume fixed-key', function () use ($memoryLimiter): void {
                $memoryLimiter->consume('rl:/limited:10.0.0.1', 1);
            }, $n);
            break;

        case 'memory-unique':
            // 冷键（大量不同用户/IP）成本
            $i = 0;
            bench('memory/consume unique-key', function () use ($memoryLimiter, &$i): void {
                $memoryLimiter->consume('rl:/limited:10.0.' . (($i++) & 255) . '.' . (($i >> 8) & 255), 1);
            }, $n);
            break;

        case 'pdo':
            if (!$pdoReady) {
                fwrite(STDERR, "跳过 pdo\n");
                break;
            }
            bench('pdo-sqlite/consume fixed-key', fn() => $pdoLimiter->consume('rl:/limited:10.0.0.1', 1), $n);
            break;

        case 'pdo-unique':
            if (!$pdoReady) {
                fwrite(STDERR, "跳过 pdo-unique\n");
                break;
            }
            $i = 0;
            bench('pdo-sqlite/consume unique-key', function () use ($pdoLimiter, &$i): void {
                $pdoLimiter->consume('rl:/limited:10.0.' . (($i++) & 255) . '.' . (($i >> 8) & 255), 1);
            }, $n);
            break;

        case 'redis':
            // 生产最常用后端：每请求 1 次 TCP 往返（本机 loopback）
            if (!$redisReady) {
                fwrite(STDERR, "跳过 redis\n");
                break;
            }
            bench('redis/consume fixed-key', fn() => $redisLimiter->consume('rl:/limited:10.0.0.1', 1), $n);
            break;

        case 'redis-mw':
            if (!$redisReady) {
                fwrite(STDERR, "跳过 redis-mw\n");
                break;
            }
            bench('mw/limited redis', fn() => $mwRedis->process($req, $handler), $n);
            break;

        default:
            fwrite(STDERR, "未知 driver: {$d}\n");
    }
}