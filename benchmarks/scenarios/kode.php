<?php

declare(strict_types=1);

namespace Kode\Bench\Scenario;

use Kode\Context\Context;
use Kode\Framework\Application;
use Kode\Http\App;
use Kode\Framework\Http\Resp;
use Kode\Framework\Resilience\Retry;
use Nyholm\Psr7\ServerRequest;

/**
 * kode/framework 压测场景。
 *
 * 为避免压测时触发全局限流返回 429，复制仓库 config/ 到临时目录并强制关闭
 * limiting；其余中间件（异常/请求ID/追踪/CORS/安全头/熔断/重试/幂等/会话/特性开关）
 * 保持生产默认，从而测得「真实全栈」吞吐。可选 $disable 用于剥离指定中间件，
 * 以隔离框架内核本身的路由+分发成本（core 场景）。
 */
final class Kode
{
    /** 压测期间始终关闭的组件（否则高并发会被限流成 429）。 */
    private const ALWAYS_OFF = ['limiting'];

    public static function scenario(string $repoRoot, array $disable, string $route): callable
    {
        $http = self::boot($repoRoot, $disable);

        return static function () use ($http, $route): ?int {
            // 用 Context::run 包裹每次请求，模拟常驻运行时（Swoole/协程）的「每请求独立上下文」隔离，
            // 避免单进程 CLI 循环下全局上下文累积导致的测量伪影，测得真实每请求成本。
            // 每请求结束显式 drain 追踪 outbox——贴合 FPM 的 register_shutdown_function（响应发出后
            // 离请求路径导出）行为；否则进程级静态 outbox 在单进程循环里无限累积，使 enqueueFlush 的
            // array_merge 逐迭代变慢、吞吐被系统性低估。
            return Context::run(
                static function () use ($http, $route): int {
                    $code = $http->handle(new ServerRequest('GET', $route))->getStatusCode();
                    // 每请求清空进程级导出队列（仅清静态 outbox，不触发网络导出）：模拟「离请求路径的
                    // drain 已在响应发出后发生」——既消除单进程循环里 outbox 无限累积导致的测量伪影，
                    // 又不把真实导出成本计入每请求在路径开销（导出本就是 v0.8.23 异步离路径设计）。
                    \Kode\Framework\Observability\Trace\Tracer::resetOutbox();

                    return $code;
                }
            );
        };
    }

    private static function boot(string $repoRoot, array $disable): App
    {
        $tmp = self::prepareRoot($repoRoot, $disable);

        $app = Application::make($tmp);

        /** @var App $http */
        $http = $app->http();

        // 纯业务逻辑场景：解析一次 DI 单例 + 构造 50 条记录的 JSON 响应。
        $http->get('/bench/json', static function () use ($app) {
            $app->makeService(Retry::class); // 触发一次容器解析

            $data = [
                'framework' => Application::VERSION,
                'now'       => date('c'),
                'items'     => array_map(
                    static fn (int $i) => ['id' => $i, 'name' => "item-$i"],
                    range(1, 50)
                ),
            ];

            return Resp::json($data);
        });

        return $http;
    }

    private static function prepareRoot(string $repoRoot, array $disable): string
    {
        $keys = [...self::ALWAYS_OFF, ...$disable];
        $tmp = sys_get_temp_dir() . '/kode-bench-' . substr(md5($repoRoot . '|' . implode(',', $keys)), 0, 10);

        if (is_dir($tmp . '/config')) {
            return $tmp; // 复用，避免重复拷贝
        }

        mkdir($tmp . '/config', 0o777, true);

        foreach (glob($repoRoot . '/config/*.php') ?: [] as $file) {
            copy($file, $tmp . '/config/' . basename($file));
        }

        // 限流：关闭（压测不做限流验证）。
        file_put_contents($tmp . '/config/limiting.php', "<?php return ['enabled' => false];\n");

        foreach ($disable as $key) {
            file_put_contents($tmp . '/config/' . $key . '.php', self::disableSnippet($key));
        }

        return $tmp;
    }

    /**
     * 生成「关闭某组件」的最小配置片段（仅置 enabled=false，其余子键沿用默认值）。
     */
    private static function disableSnippet(string $key): string
    {
        return match ($key) {
            'logging'     => "<?php return ['access_log' => ['enabled' => false]];\n",
            'session'     => "<?php return ['enabled' => false];\n",
            'idempotency' => "<?php return ['http' => ['enabled' => false]];\n",
            'feature'     => "<?php return ['enabled' => false];\n",
            'cors'        => "<?php return ['enabled' => false];\n",
            'security'    => "<?php return ['enabled' => false, 'audit' => ['enabled' => false]];\n",
            'locale'      => "<?php return ['enabled' => false];\n",
            'resilience'  => "<?php return ['breaker' => ['http' => ['enabled' => false]], 'retry' => ['http' => ['enabled' => false]]];\n",
            'observability' => "<?php return ['metrics' => ['enabled' => false], 'tracing' => ['enabled' => false]];\n",
            'metrics'     => "<?php return ['metrics' => ['enabled' => false]];\n",
            'audit'       => "<?php return ['audit' => ['enabled' => false]];\n",
            'tracing'     => "<?php return ['tracing' => ['enabled' => false]];\n",
            default       => "<?php return ['enabled' => false];\n",
        };
    }
}
