<?php

declare(strict_types=1);

namespace Kode\Bench\Scenario;

use Kode\Context\Context;
use Kode\Framework\Application;
use Kode\Framework\Http\Resp;
use Kode\Framework\Resilience\Retry;
use Kode\Framework\Security\Csrf\Csrf;
use Kode\Http\App;
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

    public static function scenario(string $repoRoot, array $disable, string $route, string $method = 'GET'): callable
    {
        // 压测会话强制走内存数组驱动：避免文件会话「每请求读写」污染 CSRF 标记路由的
        // 诚实测量。须在 boot 前设置，且无论临时配置目录是否命中缓存都要生效（故放在此处，
        // 而非仅 prepareRoot 命中缓存时跳过的分支里）。
        putenv('SESSION_DRIVER=array');
        $_ENV['SESSION_DRIVER'] = 'array';

        $http = self::boot($repoRoot, $disable);

        // 会话 Cookie 在迭代间复用：模拟真实客户端「首次握手拿到会话、后续请求携带」，
        // 使 CSRF 标记路由测量「稳态」成本（令牌签发一次、后续仅校验/回传），而非每次都
        // 重新生成令牌的合成最坏情况。
        $cookie = null;

        return static function () use ($http, $route, $method, &$cookie): ?int {
            // 用 Context::run 包裹每次请求，模拟常驻运行时（Swoole/协程）的「每请求独立上下文」隔离，
            // 避免单进程 CLI 循环下全局上下文累积导致的测量伪影，测得真实每请求成本。
            // 每请求结束显式 drain 追踪 outbox——贴合 FPM 的 register_shutdown_function（响应发出后
            // 离请求路径导出）行为；否则进程级静态 outbox 在单进程循环里无限累积，使 enqueueFlush 的
            // array_merge 逐迭代变慢、吞吐被系统性低估。
                return Context::run(
                    static function () use ($http, $route, $method, &$cookie): int {
                        $req = new ServerRequest($method, $route);
                        if ($cookie !== null) {
                            $req = $req->withCookieParams($cookie);
                        }
                        $resp = $http->handle($req);
                        if ($cookie === null) {
                            $setCookie = $resp->getHeaderLine('Set-Cookie');
                            if (preg_match('/KODE_SESSION=([^;]+)/', $setCookie, $m) === 1) {
                                $cookie = ['KODE_SESSION' => $m[1]];
                            }
                        }
                        // 每请求清空进程级导出队列（仅清静态队列，不触发真实导出）：模拟「离请求路径的
                        // drain 已在响应发出后发生」——既消除单进程循环里队列无限累积导致的测量伪影，
                        // 又不把真实导出成本计入每请求在路径开销（导出本就是 v0.8.23 异步离路径设计）。
                        \Kode\Framework\Observability\Trace\Tracer::resetOutbox();
                        \Kode\Framework\Logging\AccessLogSink::reset();
                        \Kode\Framework\Security\Audit\AuditSink::reset();

                        return $resp->getStatusCode();
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

        // CSRF 标记业务端点：用于诚实测量「全局 CSRF 中间件命中标记路由」的签发令牌开销
        // （GET 安全方法签发 + 回传 X-CSRF-Token），与未标记路由的 O(1) 早退对照。
        /** @var \Kode\Framework\Http\RouteRegistry $registry */
        $registry = app()->container->get(\Kode\Framework\Http\RouteRegistry::class);
        $csrfRoute = $http->get('/bench/csrf', static function () use ($app) {
            $app->makeService(Retry::class);

            return Resp::json(['csrf' => 'issued']);
        });
        $registry->tagCsrf($csrfRoute, true);

        // CSRF 标记 POST 端点（无令牌即 419 + 审计 csrf.failed）：用于诚实测量
        // 「校验失败且触发离路径审计」路径的吞吐（安全可观测性的真实代价）。
        $csrfPostRoute = $http->post('/bench/csrf-post', static function () {
            return Resp::json(['ok' => true]);
        });
        $registry->tagCsrf($csrfPostRoute, true);

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

        // 压测会话强制走内存数组驱动：避免文件会话「每请求读写」污染 CSRF 标记路由的
        // 诚实测量（CSRF 令牌存于会话，文件 I/O 会掩盖令牌签发本身的增量）。
        // config/session.php 经 SESSION_DRIVER 环境变量选驱动，此处直接置 array。
        putenv('SESSION_DRIVER=array');
        $_ENV['SESSION_DRIVER'] = 'array';

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
