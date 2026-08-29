<?php

/*
 * Kode Framework 全局辅助函数
 *
 * 这些函数无需 use 即可在任意位置调用，统一从 Application 容器解析服务，
 * 让业务代码保持「无框架感」的简洁写法。
 */

use Kode\Core\App;
use Kode\Framework\Feature\FeatureManager;
use Kode\Framework\Health\HealthChecker;
use Kode\Framework\Idempotency\IdempotencyManager;
use Kode\Framework\Lock\LockManager;
use Kode\Framework\Lock\LockWatchdog;
use Kode\Framework\Resilience\Retry;
use Kode\Framework\Resilience\Timeout;
use Kode\Framework\Observability\Trace\Tracer;
use Kode\Framework\ServiceDiscovery\ServiceDiscovery;
use Kode\Framework\ServiceDiscovery\ServiceInstance;
use Kode\Framework\Tenant\Storage\TenantStorageManager;
use Psr\Log\LoggerInterface;

/*
 * 注意：app() / config() / runtime() / ctx() 由 kode/core 的全局助手提供
 * （vendor/kode/core/src/Support/helpers.php），此处不再重复定义，避免冲突。
 * 本文件仅补充框架特有的助手：base_path / storage_path / env / resolve /
 * logger / cache / event / validator / jwt。
 */

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return app()?->basePath($path) ?? $path;
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return base_path('storage/' . ltrim($path, '/'));
    }
}

if (!function_exists('env')) {
    /**
     * 读取环境变量。
     *
     * @return mixed
     */
    function env(string $key, mixed $default = null)
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

if (!function_exists('resolve')) {
    /**
     * 从容器解析服务（支持构造函数自动装配与属性注入）。
     * 委托 kode/core 的 App::make()。
     *
     * @return mixed
     */
    function resolve(string $id, array $parameters = [])
    {
        $app = app();
        if ($app === null) {
            throw new \RuntimeException(
                "服务容器尚未启动，无法解析 [{$id}]。请确保在 Application::make() 引导完成后再调用助手函数。"
            );
        }

        return $app->make($id, $parameters);
    }
}

if (!function_exists('logger')) {
    /**
     * 获取默认日志器（Monolog，已实现 Psr\Log\LoggerInterface）。
     */
    function logger(): LoggerInterface
    {
        return resolve(LoggerInterface::class);
    }
}

if (!function_exists('cache')) {
    /**
     * 获取缓存管理器（kode/cache）。
     */
    function cache(): object
    {
        return resolve('cache');
    }
}

if (!function_exists('event')) {
    /**
     * 派发一个事件，返回事件对象本身。
     */
    function event(object $event): object
    {
        return resolve('events')->dispatch($event);
    }
}

if (!function_exists('validator')) {
    /**
     * 获取验证器（Symfony Validator 封装）。
     */
    function validator(): object
    {
        return resolve('validator');
    }
}

if (!function_exists('jwt')) {
    /**
     * 获取 JWT 守卫（kode/jwt 封装）。
     */
    function jwt(): object
    {
        return resolve('jwt');
    }
}

if (!function_exists('rateLimit')) {
    /**
     * 获取限流器（kode/limiting）。
     */
    function rateLimit(): object
    {
        return resolve('rate_limit');
    }
}

if (!function_exists('db')) {
    /**
     * 获取数据库静态代理（kode/database）。
     */
    function db(): object
    {
        return resolve('db');
    }
}

if (!function_exists('transaction')) {
    /**
     * 在数据库事务中执行回调（kode/database 提供原子性保证）。
     *
     * 回调内抛异常会自动回滚并原样抛出；成功则提交。也可用于手动控制：
     *   db()->beginTransaction();  db()->commit();  db()->rollback();
     *
     * 用法：
     *   $id = transaction(fn () => {
     *       $user = User::create([...]);
     *       $user->profile()->create([...]);
     *       return $user->id;
     *   });
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    function transaction(callable $callback): mixed
    {
        return db()->transaction($callback);
    }
}

if (!function_exists('schema')) {
    /**
     * 获取 Schema 便捷入口（生成即执行的 DDL 构建器，kode/database）。
     *
     * 用法：schema()->create('users', fn ($t) => $t->id()->string('name'));
     */
    function schema(): object
    {
        return resolve('schema');
    }
}

if (!function_exists('queue')) {
    /**
     * 获取默认队列连接（kode/queue）。
     */
    function queue(): object
    {
        return resolve('queue');
    }
}

if (!function_exists('session')) {
    /**
     * 获取当前请求的会话（kode/session）。
     *
     * 需在会话中间件之后调用（即 HTTP 请求处理过程中）。CLI / 未启用会话时返回 null。
     * 写入：session()->set('key', $v)；读取：session()->get('key')。
     *
     * 惰性启动：框架默认采用 LazySessionMiddleware（v1.0.0 起），会话对象在请求入口仅被创建、
     * 并不立即读盘；此处首次访问时按需 start()（读负载 + 加锁），保证随后的 set 能被正确落盘。
     * 对完全不碰会话的请求（如 /ping）零 I/O、零锁。
     */
    function session(): ?object
    {
        /** @var \Kode\Session\SessionManager $manager */
        $manager = resolve(\Kode\Session\SessionManager::class);

        $session = $manager->getSession();
        if ($session === null) {
            return null;
        }

        if (!$session->isStarted()) {
            $session->start();
        }

        return $session;
    }
}

if (!function_exists('csrf_token')) {
    /**
     * 取当前请求的 CSRF 令牌（cookie-session 应用渲染表单时嵌入隐藏域 / 元标签）。
     *
     * 令牌存于会话（由 CsrfMiddleware 同一机制签发），缺失时此处惰性补签；
     * 无会话（纯 JWT 接口 / CLI）时返回 null。
     */
    function csrf_token(): ?string
    {
        $session = session();
        if ($session === null) {
            return null;
        }

        $key = (string) (config('csrf.token_key') ?? '_csrf_token');
        $token = $session->get($key);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $session->set($key, $token);
        }

        return $token;
    }
}

if (!function_exists('csrf_token_rotate')) {
    /**
     * 轮换（重新生成）当前会话的 CSRF 令牌。
     *
     * 用于**会话固定（session fixation）防护**：在「建立身份」的节点（如登录成功、
     * 权限提升）调用一次，使旧令牌立即失效，攻击者持有的固定令牌无法再用于后续写操作。
     *
     * 用法示例（登录处理末尾）：
     * ```php
     * auth()->attempt($credentials);   // 建立会话
     * csrf_token_rotate();             // 轮换 CSRF 令牌，作废登录前的固定令牌
     * ```
     *
     * 无会话（纯 JWT / CLI）时返回 null，不产生副作用。
     */
    function csrf_token_rotate(): ?string
    {
        $session = session();
        if ($session === null) {
            return null;
        }

        $key = (string) (config('csrf.token_key') ?? '_csrf_token');
        $token = bin2hex(random_bytes(32));
        $session->set($key, $token);

        return $token;
    }
}

if (!function_exists('tenant')) {
    /**
     * 获取当前请求的租户标识（kode/context 请求级 scope）。
     *
     * 由 TenantMiddleware 在请求入口解析并写入；请求外（CLI / 未启用 / 未解析）返回 null。
     * 框架只提供此上下文原语，租户对应的存储隔离由应用层实现。
     *
     *   $tid = tenant();   // 当前租户标识，或 null
     */
    function tenant(): ?string
    {
        return \Kode\Framework\Tenant\TenantContext::id();
    }
}

if (!function_exists('aop')) {
    /**
     * 获取 AOP 内核（kode/aop），用于诊断 / 取已注册切面信息。
     *
     *   aop()->diagnostics();   // 返回内核诊断信息（已注册切面 / 代理缓存等）
     */
    function aop(): object
    {
        return resolve(\Kode\Aop\Contract\AspectKernelInterface::class);
    }
}

if (!function_exists('parallel')) {
    /**
     * 提交并行任务（kode/parallel），返回 FutureInterface。
     *
     * 自动带上 bootstrap（线程内加载业务类自动加载器）；非 ZTS 环境自动回退 sync 引擎
     * （顺序执行、API 一致、不报错）。
     *
     *   $future = parallel(fn(array $args) => heavy($args['x']), ['x' => 1]);
     *   $result = \Kode\Parallel\Parallel::await($future);
     */
    function parallel(callable $task, array $args = []): object
    {
        $bootstrap = (string) (config('parallel.bootstrap') ?: app()->basePath('vendor/autoload.php'));

        return \Kode\Parallel\Parallel::run($task, $args, $bootstrap);
    }
}

if (!function_exists('http')) {
    /**
     * 获取 HTTP 客户端（kode/http-client，PSR-18）。
     */
    function http(): object
    {
        return resolve('http');
    }
}

if (!function_exists('messaging')) {
    /**
     * 获取消息总线门面（kode/messaging）。
     */
    function messaging(): object
    {
        return resolve('messaging');
    }
}

if (!function_exists('exception_manager')) {
    /**
     * 获取异常管理器（kode/exception），负责统一异常格式化与链路追踪。
     *
     * 用法：exception_manager()->respond($e);  // → ['status' => int, 'body' => array]
     */
    function exception_manager(): object
    {
        return resolve(\Kode\Exception\ExceptionManager::class);
    }
}

if (!function_exists('snowflake')) {
    /**
     * 获取分布式 ID 生成器（Snowflake 算法，由 kode/process 提供）。
     *
     * 用法：snowflake()->id();  // 生成下一个全局唯一 ID
     */
    function snowflake(): object
    {
        return resolve('snowflake');
    }
}

if (!function_exists('breaker')) {
    /**
     * 获取熔断器管理器（算法委托 kode/fibers CircuitBreaker，经 FiberBreaker 薄适配）。
     *
     * 用法：breaker()->run('user-service', fn () => ..., fallback: fn () => ...);
     */
    function breaker(): object
    {
        return resolve('breaker');
    }
}

if (!function_exists('translator')) {
    /**
     * 获取翻译器（symfony/translation 封装）。
     */
    function translator(): object
    {
        return resolve('translator');
    }
}

if (!function_exists('lang')) {
    /**
     * 翻译文案（symfony/translation）。占位符遵循 %name% 约定。
     *
     * @param array<string, mixed> $parameters
     */
    function lang(string $key, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return translator()->trans($key, $parameters, $domain, $locale);
    }
}

if (!function_exists('route')) {
    /**
     * 根据命名路由反向生成 URL（开发者友好助手）。
     *
     * 用法：route('user.show', ['id' => 1]);  // → /users/1
     * 命名路由在 routes.php 里用 ->name('user.show') 声明。
     */
    function route(string $name, array $parameters = []): string
    {
        return resolve(\Kode\Http\App::class)->url($name, $parameters);
    }
}

if (!function_exists('process')) {
    /**
     * 获取常驻进程管理器（框架自建，基于 kode/process 的 fork + Timer 原语）。
     *
     * 用法：
     *   process()->register(new \app\process\HeartbeatWorker);
     *   process()->dryRun();        // 无 fork 验证逻辑
     *   process()->start();         // 真正 fork 常驻进程（CLI + pcntl）
     */
    function process(): object
    {
        return resolve('process.manager');
    }
}

if (!function_exists('graceful')) {
    /**
     * 获取优雅停机管理器（每个 worker 进程一个实例）。
     *
     * 用途：
     *  - graceful()->inFlight()        当前在途请求数（观测排空进度）
     *  - graceful()->isShuttingDown()  是否已进入停机（可用于 readiness 探针置否）
     *  - graceful()->stats()           观测快照（写入 metrics / 日志）
     *  - graceful()->registerCleanup(fn () => ...)  注册停机清理（flush 队列/关连接/下线等）
     *
     * 框架已在请求路径上自动包 track()、在 WorkerStopping 时自动清理；业务一般只需读取状态或追加清理。
     *
     * @return \Kode\Framework\Server\GracefulShutdown|null
     */
    function graceful(): ?object
    {
        if (app() === null || !app()->container->bound(\Kode\Framework\Server\GracefulShutdown::class)) {
            return null;
        }

        return app()->container->get(\Kode\Framework\Server\GracefulShutdown::class);
    }
}

if (!function_exists('feature')) {
    /**
     * Feature Flags 快速判定 / 取管理器。
     *
     *   feature('new-checkout')          → bool：该 flag 是否对当前上下文开启
     *   feature('beta-search', 'user:42')→ bool：按 key 稳定分桶（灰度）
     *   feature()                        → FeatureManager：取管理器（注册 resolver / 看状态）
     *
     * 判定模型见 config/feature.php 与 FeatureManager：未配置回落 default，
     * enabled=false 直接关，enabled=true 结合 rollout 灰度。
     *
     * @return \Kode\Framework\Feature\FeatureManager|bool
     */
    function feature(string $name = null, ?string $key = null): FeatureManager|bool
    {
        /** @var \Kode\Framework\Feature\FeatureManager $manager */
        $manager = resolve(\Kode\Framework\Feature\FeatureManager::class);

        if ($name === null) {
            return $manager;
        }

        return $manager->isEnabled($name, $key);
    }
}

if (!function_exists('metrics')) {
    /**
     * 获取指标注册表（Prometheus 指标，可观测性）。
     *
     * 用法：
     *   metrics()->counter('orders_total', '订单数', ['channel'])->with(['channel' => 'web'])->inc();
     *   metrics()->histogram('job_seconds', '任务耗时', [])->observe(1.2);
     */
    function metrics(): object
    {
        return resolve('metrics');
    }
}

if (!function_exists('audit')) {
    /**
     * 获取审计服务（合规审计，通常由审计中间件自动调用，业务亦可手动补记）。
     *
     * 用法：audit()->record($request, $response, $start, $userId);
     */
    function audit(): object
    {
        return resolve('audit');
    }
}

if (!function_exists('config_center')) {
    /**
     * 获取配置中心管理器（薄壳层运行时核心）。
     *
     * 用法：
     *   config_center()?->reload();                 // 运行期热重载，返回变化的顶层键
     *   $keys = config_center()?->lastChangedKeys(); // 最近一次 reload 的变化键
     *   config_center()?->sources();                // 已注册源名称
     *
     * 注意：未启用（config/center.php 的 center.enabled = false）时返回 null，调用方需判空。
     */
    function config_center(): ?object
    {
        if (app() === null || !app()->container->bound(\Kode\Framework\Config\ConfigCenter::class)) {
            return null;
        }

        return resolve(\Kode\Framework\Config\ConfigCenter::class);
    }
}

if (!function_exists('service')) {
    /**
     * 服务发现：解析上游服务实例 / 取管理器。
     *
     *   service('payment')            → ?ServiceInstance（健康实例，按默认策略负载均衡）
     *   service('payment')?->url()    → string（如 http://10.0.0.1:8080）
     *   service()                     → ServiceDiscovery（注册/心跳/统计/事件）
     *   service('search', 'random')   → ?ServiceInstance（随机策略）
     *
     * 薄壳立场：框架只内置「静态注册表（config/services.php）」，真实分布式发现
     * （Consul/Nacos/ZooKeeper/Etcd）实现为 ServiceRegistry 注入即可，框架零改动。
     *
     * @return \Kode\Framework\ServiceDiscovery\ServiceDiscovery|\Kode\Framework\ServiceDiscovery\ServiceInstance|null
     */
    function service(string $name = null, ?string $strategy = null): ServiceDiscovery|ServiceInstance|null
    {
        if (app() === null || !app()->container->bound(\Kode\Framework\ServiceDiscovery\ServiceDiscovery::class)) {
            return null;
        }

        /** @var \Kode\Framework\ServiceDiscovery\ServiceDiscovery $mgr */
        $mgr = resolve(\Kode\Framework\ServiceDiscovery\ServiceDiscovery::class);

        if ($name === null) {
            return $mgr;
        }

        return $mgr->resolve($name, $strategy);
    }
}

if (!function_exists('service_url')) {
    /**
     * 直接取某服务的完整 URL（scheme://host:port），无健康实例时返回 null。
     *
     *   service_url('payment');        // → http://10.0.0.1:8080（默认策略）
     *   service_url('search', 'random'); // → 随机健康实例地址
     */
    function service_url(string $name, ?string $strategy = null): ?string
    {
        if (app() === null || !app()->container->bound(\Kode\Framework\ServiceDiscovery\ServiceDiscovery::class)) {
            return null;
        }

        return resolve(\Kode\Framework\ServiceDiscovery\ServiceDiscovery::class)->url($name, $strategy);
    }
}

if (!function_exists('trace')) {
    /**
     * 获取链路上下文类名（trace_id / span_id，分布式追踪）。
     *
     * 用法：
     *   trace()::traceId();                    // 当前链路 ID
     *   $headers = trace()::outgoingHeaders(); // 注入到下游 HTTP 调用实现跨服务串联
     */
    function trace(): string
    {
        return \Kode\Framework\Observability\Trace\TraceContext::class;
    }
}

if (!function_exists('tracer')) {
    /**
     * 获取分布式追踪管理器（OTLP 导出）。
     *
     * 未启用（config/observability.php 的 tracing.enabled = false 或未接线）时返回 null。
     *
     * 用法：
     *   $span = tracer()->start('订单创建', ['order.id' => 123], \Kode\Framework\Observability\Trace\SpanKind::INTERNAL);
     *   try { ... } finally { tracer()->end($span); }
     *   tracer()->flush();  // 手动落盘/导出
     */
    function tracer(): ?Tracer
    {
        if (app() === null || !app()->container->bound(Tracer::class)) {
            return null;
        }

        return resolve(Tracer::class);
    }
}

if (!function_exists('tenant_storage')) {
    /**
     * 获取多租户存储隔离管理器（请求级按租户切库）。
     *
     * 未启用（config/tenant.storage.enabled = false 或未接线）时返回 null。
     *
     * 用法：
     *   $name = tenant_storage()?->connectionName('acme');   // 取某租户连接名（dry-run，不切换）
     *   $cur  = tenant_storage()?->currentConnection();       // 当前请求级激活的租户连接名
     */
    function tenant_storage(): ?TenantStorageManager
    {
        if (app() === null || !app()->container->bound(TenantStorageManager::class)) {
            return null;
        }

        return resolve(TenantStorageManager::class);
    }
}

if (!function_exists('tenant_connection')) {
    /**
     * 当前请求级激活的租户连接名（null = 未隔离 / 已恢复）。
     *
     * 与 tenant_storage()?->currentConnection() 等价，便于一行读取当前「落在哪个库」。
     */
    function tenant_connection(): ?string
    {
        return tenant_storage()?->currentConnection() ?? null;
    }
}

if (!function_exists('health')) {
    /**
     * 获取健康检查聚合器（就绪探针）。
     *
     * 未引导或 HealthServiceProvider 未接线时返回 null。
     *
     * 用法：
     *   $r = health()?->check();          // ['healthy' => bool, 'checks' => [...]]
     *   $r = health()?->check('ready');   // 就绪语义（与 /health/ready 一致）
     */
    function health(): ?HealthChecker
    {
        if (app() === null || !app()->container->bound(HealthChecker::class)) {
            return null;
        }

        return resolve(HealthChecker::class);
    }
}

if (!function_exists('lock')) {
    /**
     * 获取分布式锁管理器（薄壳层）。
     *
     * 未引导或 LockServiceProvider 未接线时返回 null。
     *
     * 用法：
     *   lock()?->acquire('cron:daily', 60);            // 返回 bool
     *   lock()?->run('report:gen', fn () => build(), 120);  // 获取 → 执行 → 释放
     *   lock()?->release('cron:daily');                // 仅 owner 可释放
     */
    function lock(): ?LockManager
    {
        if (app() === null || !app()->container->bound(LockManager::class)) {
            return null;
        }

        return resolve(LockManager::class);
    }
}

if (!function_exists('watchdog')) {
    /**
     * 获取分布式锁看门狗（自动续期装饰器，薄壳层）。
     *
     * 未引导或 LockServiceProvider 未接线时返回 null。
     *
     * 用法：
     *   watchdog()?->protect('report:daily', fn () => build(), 120);  // 持有锁期间自动续期
     *   长任务执行时间可能超过 TTL 时务必使用，避免锁过期被其他副本抢占导致重复执行。
     */
    function watchdog(): ?LockWatchdog
    {
        if (app() === null || !app()->container->bound(LockWatchdog::class)) {
            return null;
        }

        return resolve(LockWatchdog::class);
    }
}

if (!function_exists('withLock')) {
    /**
     * 带看门狗自动续期的加锁执行（长任务防锁过期）。
     *
     * 未引导时返回 null（不执行 work）。等价于 watchdog()?->protect($key, $work, $ttl)。
     *
     * 用法：
     *   withLock('report:daily', fn () => build(), 120);   // 获取 → 自动续期 → 执行 → 释放
     */
    function withLock(string $key, callable $work, int $ttl = 30): mixed
    {
        return watchdog()?->protect($key, $work, $ttl);
    }
}

if (!function_exists('idempotency')) {
    /**
     * 获取幂等管理器（薄壳层）。
     *
     * 未引导或 IdempotencyServiceProvider 未接线时返回 null。
     *
     * 与 lock() 的边界：lock = 并发互斥；idempotency = 重试安全（同一 key 在 TTL 内只处理一次）。
     *
     * 用法：
     *   idempotency()?->once($reqId, fn () => charge(), 3600);   // 首次执行，重复抛 DuplicateRequest
     *   if (idempotency()?->seen($key, 3600)) { ... }            // 首次 true，重复 false
     *   idempotency()?->forget($key);                            // 重试放行 / 运维清理
     */
    function idempotency(): ?IdempotencyManager
    {
        if (app() === null || !app()->container->bound(IdempotencyManager::class)) {
            return null;
        }

        return resolve(IdempotencyManager::class);
    }
}

if (!function_exists('retry')) {
    /**
     * 重试原语（瞬态故障恢复，与熔断器互补）。
     *
     * 框架引导后：默认退避策略来自 config/resilience.php 的 retry 段，事件经框架事件系统派发
     * （RetryAttempting / RetrySucceeded / RetryExhausted）。
     * 未引导（如纯脚本）：使用无退避的默认 Retry（仍会重试，但无等待、不派发事件）。
     *
     * @param array<string, mixed> $options
     *   attempts : 最大尝试次数（含首次），默认 3
     *   backoff  : BackoffStrategy|null（覆盖默认退避；如 new ExponentialBackoff）
     *   retryOn  : callable(\Throwable):bool | list<class-string<\Throwable>> | null（null=任何异常都重试）
     *   timeout  : float|null 总预算秒数（超出停止重试，避免长尾）
     *   label    : string 日志/事件标识
     *
     * 用法：
     *   retry(fn () => $client->call(), attempts: 3);
     *   retry($op, ['attempts' => 5, 'retryOn' => [ConnectionException::class], 'timeout' => 30]);
     *   retry($op, ['backoff' => new \Kode\Framework\Resilience\Backoff\DecorrelatedJitterBackoff]);
     *
     * @return mixed 操作成功返回值
     */
    function retry(callable $operation, array $options = []): mixed
    {
        if (app() !== null && app()->container->bound(Retry::class)) {
            return resolve(Retry::class)->run($operation, $options);
        }

        // 降级：未引导时也可用（无默认退避、无事件派发）。
        return (new Retry())->run($operation, $options);
    }
}

if (!function_exists('timeout')) {
    /**
     * 超时原语（操作级执行预算，与熔断 / 重试 / 幂等共构「稳定性四件套」）。
     *
     * 框架引导后：默认秒数来自 config/resilience.php 的 timeout 段，事件经框架事件系统派发
     * （TimeoutExceeded）。底层抢占由 active runtime（kode/fibers）提供——对会挂起的任务真实生效。
     *
     * 未引导（如纯脚本）：使用默认 5s 的 Timeout（事件不派发）。
     *
     * @param array<string, mixed> $options
     *   seconds   : float 允许的操作秒数，默认 5.0
     *   label     : string 日志/事件标识
     *   scheduler : 'fiber'|'pcntl'|'sync'|null（null=自动：有 fiber 走 fiber，否则 sync）
     *   fallback  : callable(TimeoutExceeded): mixed 超时降级回调
     *   (throw 由 config 控制，默认 true：超时抛 TimeoutExceeded)
     *
     * 用法：
     *   timeout(fn () => $client->call(), seconds: 2.0);
     *   timeout($op, ['seconds' => 1.5, 'fallback' => fn () => $cached]);
     *   timeout($op, ['scheduler' => 'sync']);   // 无 fiber 环境的保底（仅越界检测）
     *
     * @return mixed 操作成功返回值、fallback 返回值、或 null（不抛且未配 fallback）
     *
     * @throws \Kode\Framework\Resilience\TimeoutExceeded 超时且未提供 fallback
     */
    function timeout(callable $operation, array $options = []): mixed
    {
        if (app() !== null && app()->container->bound(Timeout::class)) {
            return resolve(Timeout::class)->run($operation, $options);
        }

        // 降级：未引导时也可用（默认 5s、无事件派发）。
        return (new Timeout())->run($operation, $options);
    }
}

if (!function_exists('openapi')) {
    /**
     * 获取 OpenAPI 生成器（API 文档自动化）。
     *
     * 用法：
     *   openapi()->generate();  // 返回 spec 数组
     *   openapi()->toJson();    // 返回格式化 JSON
     */
    function openapi(): object
    {
        return resolve('apidoc');
    }
}
