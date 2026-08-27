<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Attributes\Reader;
use Kode\Framework\Application;
use Kode\Framework\Http\ControllerScanner;
use Kode\Framework\Http\Middleware\AccessLogMiddleware;
use Kode\Framework\Http\RouteResolver;
use Kode\Framework\Logging\AccessLogSink;
use Kode\Framework\Http\Middleware\LocaleMiddleware;
use Kode\Framework\Http\RouteRegistry;
use Kode\Framework\Providers\ServiceProvider;
use Kode\Exception\ExceptionManager;
use Kode\Framework\Http\Resp;
use Psr\Log\LoggerInterface;
use Kode\Framework\Http\RateLimit\LimiterFactory;
use Kode\Framework\Http\RateLimit\RateLimitAttributeReader;
use Kode\Framework\Health\HealthChecker;
use Kode\Framework\Http\Middleware\RateLimitMiddleware;
use Kode\Framework\Resilience\Retry;
use Kode\Framework\Resilience\RetryMiddleware;
use Kode\Framework\Resilience\Breaker;
use Kode\Framework\Resilience\CircuitBreakerMiddleware;
use Kode\Framework\Resilience\CircuitBreakerAttributeReader;
use Kode\Framework\Resilience\RetryAttributeReader;
use Kode\Framework\Idempotency\IdempotencyAttributeReader;
use Kode\Framework\Http\Middleware\ExceptionMiddleware;
use Kode\Framework\Http\Middleware\TransactionMiddleware;
use Kode\Framework\Http\Middleware\JsonBodyMiddleware;
use Kode\Framework\Http\Middleware\ConnectionCleanupMiddleware;
use Kode\Framework\Feature\FeatureManager;
use Kode\Framework\Feature\FeatureRegistry;
use Kode\Framework\Feature\FeatureAttributeReader;
use Kode\Framework\Feature\Middleware\FeatureMiddleware;
use Kode\Framework\Security\Csrf\CsrfMiddleware;
use Kode\Framework\Security\Csrf\CsrfAttributeReader;
use Kode\Database\Db\Db;
use Kode\Http\App;
use Kode\Http\Middleware\CorsMiddleware;
use Kode\Http\Middleware\RequestId;
use Kode\Http\Middleware\SecurityHeaders;

/**
 * HTTP 服务提供者
 *
 * 构建 kode/http 的 App（路由器 + 中间件管道 + 运行器），
 * 接入全局异常处理（日志 + 结构化 JSON）、404 处理，并加载路由文件。
 */
final class HttpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(App::class, function (): App {
            return App::create((bool) $this->config('app.debug', false));
        });

        // 路由来源登记表（route:list 命令按来源/文件聚合用）。
        $this->container->singleton(RouteRegistry::class, fn(): RouteRegistry => new RouteRegistry());

        // 路由匹配共享器：把「全局中间件按路由属性按需挂载」所需的 router->match() 在单次请求内
        // 只执行一次（结果挂请求属性供后续中间件复用），避免每个路由感知型中间件各自全表重匹配。
        $this->container->singleton(RouteResolver::class, fn(): RouteResolver => new RouteResolver($this->container->get(App::class)->getRouter()));

        // 属性路由扫描器依赖（kode/attributes Reader，无缓存即可）。
        $this->container->singleton(Reader::class, fn(): Reader => new Reader());
    }

    public function boot(): void
    {
        /** @var App $app */
        $app = $this->container->get(App::class);

        /** @var RouteResolver $resolver 跨中间件复用单次请求内的路由匹配结果 */
        $resolver = $this->container->get(RouteResolver::class);
        /** @var RouteRegistry $registry 路由属性登记表（限流/韧性/幂等/CSRF/特性 共享），
         *  必须置于各 if 块之外：熔断/重试/幂等中间件的 opt-in 门控都依赖它，而限流可能因
         *  limiting 被关闭而跳过其定义块，导致此处变量未定义、门控失效（退化成每请求都跑全套逻辑）。 */
        $registry = $this->container->get(RouteRegistry::class);

        // 用框架异常中间件包裹 kode/http 默认管线：
        // 错误响应 100% 交给 kode/exception（结构化 JSON，含 file/line/chain，无 HTML 调试页）。
        $this->pipeExceptionHandling($app);

        // 连接生命周期收口：挂在最外层（prepend 于 ExceptionMiddleware 之外），保证响应产出后
        // 一定会回收泄漏事务 / 按需释放缓存连接，无论请求成功还是异常（仅靠 finally 保证）。
        // 默认开启、零开销：正常路径无泄漏事务则不触碰连接；release_per_request 默认关以保留
        // 常驻进程的连接池性能。
        $app->getDispatcher()->prepend(new ConnectionCleanupMiddleware(
            releasePerRequest: (bool) $this->config('database.release_per_request', false),
            leakRollback: (bool) $this->config('database.leak_rollback', true),
            logger: $this->container->has(LoggerInterface::class)
                ? $this->container->get(LoggerInterface::class)
                : null,
        ));

        // 404 / 405：API 框架统一返回标准 JSON（不含堆栈，异常由 ExceptionMiddleware 处理）。
        $app->notFound(fn(): \Kode\Http\Response => Resp::error('Not Found', 404));

        $app->methodNotAllowed(fn(): \Kode\Http\Response => Resp::error('Method Not Allowed', 405));

        // ---- 全局中间件链（顺序：追踪 → CORS → 安全头）----
        // 这些是企业级 API 基础设施，默认开启、配置驱动，可按需关闭。
        // 实现全部委托 kode/http 原生中间件，框架只做「配置映射 + 开关」胶水。
        if (!empty($this->config('security.request_id', true))) {
            $app->use(new RequestId(header: 'X-Request-Id'));
        }

        // 访问日志：紧跟 RequestId 之后，确保能记录链路 ID；记录 method/uri/status/延迟。
        if (!empty($this->config('logging.access_log.enabled', true))) {
            /** @var LoggerInterface $logger */
            $logger = $this->container->get(LoggerInterface::class);
            $async = (bool) ($this->config('logging.access_log.async', true) ?? true);
            $sink = $this->container->bound(AccessLogSink::class)
                ? $this->container->get(AccessLogSink::class)
                : null;

            $app->use(new AccessLogMiddleware($logger, true, $sink, $async, $this->trustedProxies()));
        }

        if (!empty($this->config('cors.enabled', true))) {
            $app->use(new CorsMiddleware($this->corsConfig()));
        }

        if (!empty($this->config('security.enabled', true))) {
            $app->use(new SecurityHeaders(...$this->securityHeadersConfig()));
        }

        // 国际化：Accept-Language 自动选语种（默认开启，config/locale.php 可关）。
        // 语种写入 kode/context（按 fiber/协程隔离），由 Translator 自动读取，避免并发污染。
        if (!empty($this->config('locale.enabled', true))) {
            $app->use(new LocaleMiddleware((array) $this->config('locale', [])));
        }

        // 全局限流：按路由上的 #[RateLimit] 细粒度限流，否则回落全局默认。
        // driver=redis 时即为分布式限流。开关见 config/limiting.enabled。
        if (!empty($this->config('limiting.enabled', true))) {
            /** @var LimiterFactory $factory */
            $factory = $this->container->get(LimiterFactory::class);

            $app->use(new RateLimitMiddleware(
                $app->getRouter(),
                $registry,
                $factory,
                (array) $this->config('limiting', []),
                $resolver,
                $this->trustedProxies(),
            ));
        }

        // 边缘韧性三件套：改为「路由级挂载」（§6 P2 深化）——仅对声明 #[CircuitBreaker]/#[Retry]/
        // #[Idempotency] 的路由挂载对应中间件（见 scanAttributeRoutes / scanExplicit*），彻底移出全局
        // 管道，未声明路由的默认栈不再付出这三个中间件帧开销。RouteRegistry 标记保留为中间件内部
        // O(1) 早退门控的元数据来源。挂载顺序 breaker(外) → idempotency → retry(内) 与原全局一致。
        // 注：不再经 $app->use() 注册到全局管道；此处绑定为单例（启用时），供属性扫描 / 显式路由扫描 /
        // 测试统一解析挂载。
        $container = $this->container;
        $breakerHttpCfg = (array) $this->config('resilience.breaker.http', []);
        $idemHttpCfg = (array) $this->config('idempotency.http', []);
        $retryHttpCfg = (array) $this->config('resilience.retry.http', []);
        $dispatcher = static fn (object $event): object => event($event);

        if (!empty($breakerHttpCfg['enabled'] ?? true)) {
            $this->container->singleton(CircuitBreakerMiddleware::class, static fn(): CircuitBreakerMiddleware =>
                new CircuitBreakerMiddleware(
                    $container->get(Breaker::class),
                    $breakerHttpCfg,
                    $dispatcher,
                    $app->getRouter(),
                    $registry,
                    $resolver,
                ));
        }

        if (!empty($idemHttpCfg['enabled'] ?? true)) {
            $this->container->singleton(\Kode\Framework\Idempotency\IdempotencyMiddleware::class, static fn(): \Kode\Framework\Idempotency\IdempotencyMiddleware =>
                new \Kode\Framework\Idempotency\IdempotencyMiddleware(
                    $container->get(\Kode\Framework\Idempotency\IdempotencyManager::class),
                    $idemHttpCfg,
                    $app->getRouter(),
                    $registry,
                    $resolver,
                ));
        }

        if (!empty($retryHttpCfg['enabled'] ?? true)) {
            $this->container->singleton(RetryMiddleware::class, static fn(): RetryMiddleware =>
                new RetryMiddleware(
                    $container->get(Retry::class),
                    $retryHttpCfg,
                    $app->getRouter(),
                    $registry,
                    $resolver,
                ));
        }

        $cbMiddleware = $this->container->bound(CircuitBreakerMiddleware::class)
            ? $this->container->get(CircuitBreakerMiddleware::class) : null;
        $idemMiddleware = $this->container->bound(\Kode\Framework\Idempotency\IdempotencyMiddleware::class)
            ? $this->container->get(\Kode\Framework\Idempotency\IdempotencyMiddleware::class) : null;
        $retryMiddleware = $this->container->bound(RetryMiddleware::class)
            ? $this->container->get(RetryMiddleware::class) : null;

        // 请求体 JSON 健壮性：声明 application/json 但 body 非法时显式 400（默认关闭）。
        if (!empty($this->config('http.json_strict', false))) {
            $app->use(new JsonBodyMiddleware(
                enabled: true,
                skipPaths: (array) $this->config('http.json_skip_paths', ['/health', '/metrics', '/ping']),
            ));
        }

        // 请求级数据库事务：写请求原子化（默认关闭，需 database.auto_transaction = true）。
        if (!empty($this->config('database.auto_transaction', false))) {
            $app->use(new TransactionMiddleware(
                enabled: true,
                writeMethods: ['POST', 'PUT', 'PATCH', 'DELETE'],
                skipPaths: (array) $this->config('database.transaction_skip_paths', ['/health', '/metrics', '/ping']),
            ));
        }

        // 会话：kode/session 接进生命周期（此前装了却没接，能力静默失接）。
        // 用框架自建惰性中间件（LazySessionMiddleware）替换 vendor 默认实现：仅创建未启动会话对象，
        // 业务侧 session() 助手首次访问才 start；响应时仅当会话被用过才落盘 + Set-Cookie。
        // 避免「每个请求（含 /ping）都文件锁 + 读 + 写」的灾难性开销（原 ~154 req/s → 接近裸内核）。
        if (!empty($this->config('session.enabled', true))) {
            /** @var \Kode\Session\SessionManager $manager */
            $manager = $this->container->get(\Kode\Session\SessionManager::class);
            $app->use(new \Kode\Framework\Session\Middleware\LazySessionMiddleware(
                $manager,
                (array) $this->config('session', []),
            ));
        }

        // 多租户上下文原语（kode/context）：请求入口解析租户 → 写入每请求隔离 scope。
        // 业务侧用 tenant() 读取；存储隔离由应用层自行实现（框架不越界）。
        if (!empty($this->config('tenant.enabled', false))) {
            /** @var \Kode\Framework\Tenant\TenantMiddleware $tenantMiddleware */
            $tenantMiddleware = $this->container->get(\Kode\Framework\Tenant\TenantMiddleware::class);
            $app->use($tenantMiddleware);
        }

        // 多租户存储隔离脚手架（运行于 TenantMiddleware 内层）：解析到租户后，在请求级把默认
        // DB 连接切换到该租户连接，响应后恢复。未启用 storage 或租户为空时零开销放行。
        if (!empty($this->config('tenant.storage.enabled', false))) {
            /** @var \Kode\Framework\Tenant\Storage\TenantStorageMiddleware $storageMiddleware */
            $storageMiddleware = $this->container->get(\Kode\Framework\Tenant\Storage\TenantStorageMiddleware::class);
            $app->use($storageMiddleware);
        }

        // 功能开关（Feature Flags）：路由级 gating（#[Feature] 声明），关闭返回 fallback。
        // 总开关见 config/feature.enabled；分桶键 X-User-Id → X-Tenant-Id → 客户端 IP。
        if (!empty($this->config('feature.enabled', true))) {
            /** @var FeatureManager $featureManager */
            $featureManager = $this->container->get(FeatureManager::class);
            /** @var FeatureRegistry $featureRegistry */
            $featureRegistry = $this->container->get(FeatureRegistry::class);

            $app->use(new FeatureMiddleware(
                $app->getRouter(),
                $featureRegistry,
                $featureManager,
                (array) $this->config('feature', []),
                $resolver,
                $this->trustedProxies(),
            ));
        }

        // CSRF 防护（薄壳层）：全局中间件，但「按需命中」——仅 #[Csrf] 标记或配置 auto_apply_unsafe
        // 命中的路由才触发令牌校验，其余路由 O(1) 早退零开销（满足「企业中间件不影响响应」）。
        // 依赖会话承载令牌；无会话的纯 JWT 接口即便被标记也安全跳过。
        if (!empty($this->config('csrf.enabled', true))) {
            /** @var RouteRegistry $registry */
            $registry = $this->container->get(RouteRegistry::class);

            $app->use(new CsrfMiddleware(
                $app->getRouter(),
                $registry,
                (array) $this->config('csrf', []),
                $resolver,
            ));
        }

        // ---- 框架内置探针（适配 k8s / 负载均衡健康检查）----
        $this->registerHealthEndpoints($app);

        // 加载路由（属性路由 + 显式路由文件，每条打来源标签供 route:list 使用）。
        /** @var RouteRegistry $registry */
        $registry = $this->container->get(RouteRegistry::class);

        // 1) 属性路由先注册（约定优于配置，自动发现）。
        $this->scanAttributeRoutes($app, $registry);

        // 2) 显式路由文件后注册（可覆盖同名路径，named 路由在此声明）。
        foreach ($this->resolveRouteSources() as $label => $file) {
            $this->loadRoutes($app, $file, $label, $registry);
        }

        // 3) 为显式路由（[类, 方法] / "类@方法" / 类名 等可反射 handler）补充
        //    #[RateLimit] 规则登记。属性路由已在扫描阶段登记，此处仅处理显式路由。
        $this->scanExplicitRateLimits($app, $registry);

        // 4) 为显式路由（可反射 handler）补充 #[Feature] 开关登记（与限流同范式）。
        if (!empty($this->config('feature.enabled', true))) {
            /** @var FeatureRegistry $featureRegistry */
            $featureRegistry = $this->container->get(FeatureRegistry::class);
            $this->scanExplicitFeatures($app, $featureRegistry);
        }

        // 4b) 为显式路由（可反射 handler）补充 #[Csrf] 防护标记登记（与限流同范式）。
        if (!empty($this->config('csrf.enabled', true))) {
            $this->scanExplicitCsrf($app, $registry);
        }

        // 4c) 为显式路由（可反射 handler）补充 #[CircuitBreaker] / #[Retry] / #[Idempotency] 标记登记
        //     + 路由级中间件挂载（与属性路由同范式）：属性路由已在扫描阶段挂载，此处仅处理显式路由。
        if (!empty($this->config('resilience.breaker.http.enabled', true))) {
            $this->scanExplicitCircuitBreakers($app, $registry, $cbMiddleware);
        }
        if (!empty($this->config('resilience.retry.http.enabled', true))) {
            $this->scanExplicitRetries($app, $registry, $retryMiddleware);
        }
        if (!empty($this->config('idempotency.http.enabled', true))) {
            $this->scanExplicitIdempotencies($app, $registry, $idemMiddleware);
        }
    }

    /**
     * 用框架异常中间件包裹 kode/http 内置管线。
     *
     * kode/http 的 App 在构造时已把 JsonErrorHandlerMiddleware 挂为最外层；
     * 框架需要让自家的 {@see ExceptionMiddleware}（产出结构化 JSON、含 file/line/
     * chain、透传 X-Trace-Id）成为最外层。这里直接对调度管线调用 prepend()，
     * 把 ExceptionMiddleware 插到 JsonErrorHandlerMiddleware 之前即可——异常被前者
     * 捕获后直接返回，JsonErrorHandlerMiddleware 不再会被触达，行为等价且无需反射
     * 改写 App 的私有 dispatcher 属性。
     */
    private function pipeExceptionHandling(App $app): void
    {
        /** @var ExceptionManager $manager */
        $manager = $this->container->get(ExceptionManager::class);

        $app->getDispatcher()->prepend(new ExceptionMiddleware($manager));
    }

    /**
     * 将框架 config/cors.php 映射为 kode/http 原生 CorsMiddleware 的配置键。
     *
     * @return array<string, mixed>
     */
    private function corsConfig(): array
    {
        $cors = (array) $this->config('cors', []);

        $origins = $cors['allowed_origins'] ?? '*';
        $credentials = !empty($cors['allow_credentials']);
        // 互斥校验（M1）：origin '*' + credentials true 属规范禁止，回显 * 并带凭证会泛化凭证域。
        $isWildcard = $origins === '*' || (is_array($origins) && in_array('*', $origins, true));
        if ($isWildcard && $credentials) {
            throw new \RuntimeException('CORS 配置错误：allowed_origins 为 "*" 时不可开启 allow_credentials（规范禁止），请收敛为具体域名或关闭凭证。');
        }

        return [
            'origin' => $origins,
            'methods' => $cors['allowed_methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            'headers' => $cors['allowed_headers'] ?? ['Content-Type', 'Authorization'],
            'expose_headers' => $cors['exposed_headers'] ?? [],
            'max_age' => (int) ($cors['max_age'] ?? 86400),
            'credentials' => $credentials,
        ];
    }

    /**
     * 将框架 config/security.php 映射为 kode/http 原生 SecurityHeaders 的构造参数。
     *
     * @return array{0: array<string, string>, 1: bool}
     */
    private function securityHeadersConfig(): array
    {
        $sec = (array) $this->config('security', []);

        $headers = [];
        if (!empty($sec['nosniff'])) {
            $headers['X-Content-Type-Options'] = 'nosniff';
        }
        if (!empty($sec['frame_options'])) {
            $headers['X-Frame-Options'] = (string) $sec['frame_options'];
        }
        if (!empty($sec['referrer_policy'])) {
            $headers['Referrer-Policy'] = (string) $sec['referrer_policy'];
        }
        if (!empty($sec['xss_protection']) && (string) $sec['xss_protection'] !== '0') {
            $headers['X-XSS-Protection'] = (string) $sec['xss_protection'];
        }
        // 自定义 HSTS 串（如 'max-age=31536000; includeSubDomains'）直接下发。
        $hsts = !empty($sec['hsts']) ? (string) $sec['hsts'] : '';
        if ($hsts !== '') {
            $headers['Strict-Transport-Security'] = $hsts;
        }

        // 进阶安全头（合规加固）：CSP / Permissions-Policy / COOP / CORP / COEP。
        $csp = $sec['csp'] ?? '';
        if ($csp !== '' && $csp !== false) {
            $headers['Content-Security-Policy'] = (string) $csp;
        }
        if (!empty($sec['permissions_policy'])) {
            $headers['Permissions-Policy'] = (string) $sec['permissions_policy'];
        }
        if (!empty($sec['cross_origin_opener_policy'])) {
            $headers['Cross-Origin-Opener-Policy'] = (string) $sec['cross_origin_opener_policy'];
        }
        if (!empty($sec['cross_origin_resource_policy'])) {
            $headers['Cross-Origin-Resource-Policy'] = (string) $sec['cross_origin_resource_policy'];
        }
        if (!empty($sec['cross_origin_embedder_policy'])) {
            $headers['Cross-Origin-Embedder-Policy'] = (string) $sec['cross_origin_embedder_policy'];
        }

        // hsts=false：HSTS 已通过 $headers 直接下发，避免原生中间件二次写入默认值。
        return [$headers, false];
    }

    /**
     * 扫描已注册路由的 handler，对可反射出控制器类/方法的，读取 #[RateLimit]。
     *
     * 属性路由（ControllerScanner）注册的 handler 是闭包、已在扫描阶段登记，
     * 这里跳过闭包、只处理显式路由的可反射 handler，避免重复。
     */
    private function scanExplicitRateLimits(App $app, RouteRegistry $registry): void
    {
        /** @var RateLimitAttributeReader $reader */
        $reader = $this->container->get(RateLimitAttributeReader::class);

        foreach ($app->getRouter()->getRoutes() as $route) {
            // 已由属性路由扫描登记过（闭包 handler）则跳过。
            if ($registry->rateLimitsOf($route) !== []) {
                continue;
            }

            [$class, $method] = $this->resolveHandler($route->getHandler());
            if ($class === null) {
                continue;
            }

            /** @var list<\Kode\Limiting\Attribute\RateLimit> $rules */
            $rules = $reader->read($class, $method);
            $registry->tagRateLimits($route, $rules);
        }
    }

    /**
     * 扫描显式路由 handler，对可反射出控制器类/方法且带 #[Feature] 的，登记到 FeatureRegistry。
     *
     * 与 scanExplicitRateLimits 同一范式：属性路由（ControllerScanner）已在扫描阶段登记，
     * 此处仅处理显式路由里可被反射出 [类, 方法] 的 handler，闭包 handler 跳过。
     */
    private function scanExplicitFeatures(App $app, FeatureRegistry $registry): void
    {
        /** @var FeatureAttributeReader $reader */
        $reader = $this->container->get(FeatureAttributeReader::class);

        foreach ($app->getRouter()->getRoutes() as $route) {
            // 已由属性路由扫描登记过（闭包 handler）则跳过。
            if ($registry->flagOf($route) !== null) {
                continue;
            }

            [$class, $method] = $this->resolveHandler($route->getHandler());
            if ($class === null) {
                continue;
            }

            $entry = $reader->read($class, $method);
            if ($entry !== null) {
                $registry->tag($route, $entry['flag'], $entry['fallback']);
            }
        }
    }

    /**
     * 扫描显式路由 handler，对可反射出控制器类/方法且带 #[Csrf] 的，登记到 RouteRegistry。
     *
     * 与 scanExplicitRateLimits / scanExplicitFeatures 同一范式：属性路由（ControllerScanner）
     * 已在扫描阶段登记，此处仅处理显式路由里可被反射出 [类, 方法] 的 handler，闭包 handler 跳过。
     */
    private function scanExplicitCsrf(App $app, RouteRegistry $registry): void
    {
        $reader = new CsrfAttributeReader();

        foreach ($app->getRouter()->getRoutes() as $route) {
            // 已由属性路由扫描登记过则跳过（避免重复）。
            if ($registry->csrfOf($route)) {
                continue;
            }

            [$class, $method] = $this->resolveHandler($route->getHandler());
            if ($class === null) {
                continue;
            }

            if ($reader->isPresent($class, $method)) {
                $registry->tagCsrf($route, true);
            }
        }
    }

    /**
     * 扫描显式路由 handler，对可反射出控制器类/方法且带 #[CircuitBreaker] 的，登记到 RouteRegistry。
     *
     * 与 scanExplicitCsrf / scanExplicitRateLimits / scanExplicitFeatures 同一范式：
     * 属性路由（ControllerScanner）已在扫描阶段登记，此处仅处理显式路由里可被反射的 handler。
     */
    private function scanExplicitCircuitBreakers(App $app, RouteRegistry $registry, ?CircuitBreakerMiddleware $mw): void
    {
        if ($mw === null) {
            return;
        }

        $reader = new CircuitBreakerAttributeReader();

        foreach ($app->getRouter()->getRoutes() as $route) {
            if ($registry->circuitBreakerOf($route)) {
                continue;
            }

            [$class, $method] = $this->resolveHandler($route->getHandler());
            if ($class === null) {
                continue;
            }

            if ($reader->isPresent($class, $method)) {
                $registry->tagCircuitBreaker($route, true);
                $route->middleware($mw);
            }
        }
    }

    /**
     * 扫描显式路由 handler，对可反射出控制器类/方法且带 #[Retry] 的，登记到 RouteRegistry。
     *
     * 同 scanExplicitCircuitBreakers 范式。
     */
    private function scanExplicitRetries(App $app, RouteRegistry $registry, ?RetryMiddleware $mw): void
    {
        if ($mw === null) {
            return;
        }

        $reader = new RetryAttributeReader();

        foreach ($app->getRouter()->getRoutes() as $route) {
            if ($registry->retryOf($route)) {
                continue;
            }

            [$class, $method] = $this->resolveHandler($route->getHandler());
            if ($class === null) {
                continue;
            }

            if ($reader->isPresent($class, $method)) {
                $registry->tagRetry($route, true);
                $route->middleware($mw);
            }
        }
    }

    /**
     * 扫描显式路由 handler，对可反射出控制器类/方法且带 #[Idempotency] 的，登记到 RouteRegistry。
     *
     * 同 scanExplicitCircuitBreakers 范式。
     */
    private function scanExplicitIdempotencies(App $app, RouteRegistry $registry, ?\Kode\Framework\Idempotency\IdempotencyMiddleware $mw): void
    {
        if ($mw === null) {
            return;
        }

        $reader = new IdempotencyAttributeReader();

        foreach ($app->getRouter()->getRoutes() as $route) {
            if ($registry->idempotencyOf($route)) {
                continue;
            }

            [$class, $method] = $this->resolveHandler($route->getHandler());
            if ($class === null) {
                continue;
            }

            if ($reader->isPresent($class, $method)) {
                $registry->tagIdempotency($route, true);
                $route->middleware($mw);
            }
        }
    }

    /**
     * 把各种形态的路由 handler 解析为 [类名, 方法名]。无法反射的（闭包等）返回 [null, null]。
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveHandler(mixed $handler): array
    {
        if (is_array($handler) && count($handler) === 2 && is_string($handler[0])) {
            return [$handler[0], is_string($handler[1]) ? $handler[1] : null];
        }

        if (is_string($handler) && class_exists($handler)) {
            return [$handler, null];
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            return [$class, $method];
        }

        return [null, null];
    }

    /**
     * 扫描属性路由：读取 attributes 配置下的控制器目录，自动注册路由。
     *
     * 默认即开启、递归扫描（Scanner 已递归子目录），新建任意子文件夹即成为模块，
     * 无需任何配置开关；plugins/<name>/src/Controllers 在目录存在时自动纳入。
     */
    private function scanAttributeRoutes(App $app, RouteRegistry $registry): void
    {
        /** @var Reader $reader */
        $reader = $this->container->get(Reader::class);
        /** @var FeatureRegistry $featureRegistry */
        $featureRegistry = $this->container->get(FeatureRegistry::class);
        /** @var FeatureAttributeReader $featureReader */
        $featureReader = $this->container->get(FeatureAttributeReader::class);
        // 韧性中间件在 boot() 中启用时绑定为容器单例；此处从容器解析（未绑定即 null，scanner 内部跳过挂载）。
        // 注意：不可引用 boot() 的局部变量 $cbMiddleware/$retryMiddleware/$idemMiddleware（不在本方法作用域）。
        $container = $this->container;
        $cbMiddleware = $container->bound(CircuitBreakerMiddleware::class)
            ? $container->get(CircuitBreakerMiddleware::class) : null;
        $retryMiddleware = $container->bound(RetryMiddleware::class)
            ? $container->get(RetryMiddleware::class) : null;
        $idemMiddleware = $container->bound(\Kode\Framework\Idempotency\IdempotencyMiddleware::class)
            ? $container->get(\Kode\Framework\Idempotency\IdempotencyMiddleware::class) : null;
        $scanner = new ControllerScanner(
            $app,
            $reader,
            $registry,
            featureRegistry: $featureRegistry,
            featureReader: $featureReader,
            circuitBreakerMiddleware: $cbMiddleware,
            retryMiddleware: $retryMiddleware,
            idempotencyMiddleware: $idemMiddleware,
        );

        /** @var array<string, string> $dirs */
        $dirs = (array) $this->config('routes.attributes.controllers', []);
        // 配置里写的是「相对项目根」的子路径；引导期 app() 尚未就绪，base_path()
        // 会退化为相对 CWD，故此处用真实的 path.base 拼成绝对路径，避免依赖工作目录。
        $base = (string) $this->config('path.base', '');
        $dirs = $this->resolveAbsoluteDirs($dirs, $base);
        $dirs = $this->withApplicationControllerDirs($dirs);
        $dirs = $this->withPluginControllerDirs($dirs);
        $scanner->scan($dirs);
    }

    /**
     * 把配置中的「相对项目根」目录解析为绝对路径。
     *
     * 配置期 app() 可能尚未就绪，base_path() 会退化成相对 CWD 的路径；
     * 此处统一用真实的 path.base 拼接，保证从不同工作目录启动时路由发现一致。
     *
     * @param array<string, string> $dirs
     * @return array<string, string>
     */
    private function resolveAbsoluteDirs(array $dirs, string $base): array
    {
        if ($base === '') {
            return $dirs;
        }

        $resolved = [];
        foreach ($dirs as $key => $dir) {
            $resolved[$key] = $this->isAbsolute((string) $dir) ? (string) $dir : $base . '/' . ltrim((string) $dir, '/');
        }

        return $resolved;
    }

    private function isAbsolute(string $path): bool
    {
        return $path !== '' && ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1);
    }

    /**
     * 目录存在时，把 plugins/<name>/src/Controllers 纳入属性扫描（自动发现，无需开关）。
     *
     * @param array<string, string> $dirs
     * @return array<string, string>
     */
    private function withPluginControllerDirs(array $dirs): array
    {
        $pluginsDir = $this->config('path.base') . '/plugins';
        if (!is_dir($pluginsDir)) {
            return $dirs;
        }

        foreach (glob($pluginsDir . '/*', GLOB_ONLYDIR) ?: [] as $pluginDir) {
            $name = basename($pluginDir);
            foreach (['src/Controllers', 'Controllers'] as $rel) {
                $candidate = $pluginDir . '/' . $rel;
                if (is_dir($candidate)) {
                    $dirs['plugin:' . $name] = $candidate;
                    break;
                }
            }
        }

        return $dirs;
    }

    /**
     * 多应用自动发现（零开关）：app/{App}/routes.php 存在即视为一个子应用，
     * 其 http/controllers 或 controllers 目录自动纳入属性路由扫描。
     *
     * 约定（与主应用对齐）：
     *   - 应用目录：app/admin/routes.php、app/api/routes.php ...（目录名即应用名，小写）；
     *   - 控制器目录：app/{App}/http/controllers 或 app/{App}/controllers；
     *   - namespace：与目录严格对应（PSR-4 app\ → app/），如
     *     app/admin/http/controllers/AdminPanelController.php → app\admin\http\controllers\AdminPanelController。
     *   - 路由来源标签：app:<App>（route:list 分组展示）。
     *
     * 不满足「存在 routes.php」的普通目录（app/http、app/services、app/models 等）不受影响，
     * 不会被误判为子应用。
     *
     * @return list<string> 子应用目录绝对路径列表
     */
    private function discoverApplications(): array
    {
        $base = (string) $this->config('path.base', '');
        $apps = [];

        foreach (glob($base . '/app/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (basename((string) $dir) === 'routes') {
                continue; // 主应用路由目录（归属主应用，见 resolveRouteSources）
            }
            if (is_file($dir . '/routes.php')) {
                $apps[] = $dir;
            }
        }

        return $apps;
    }

    /**
     * 把子应用控制器目录纳入属性扫描（自动发现，无需开关）。
     *
     * @param array<string, string> $dirs
     * @return array<string, string>
     */
    private function withApplicationControllerDirs(array $dirs): array
    {
        foreach ($this->discoverApplications() as $appDir) {
            $name = basename((string) $appDir);
            foreach (['http/controllers', 'controllers'] as $rel) {
                $candidate = $appDir . '/' . $rel;
                if (is_dir($candidate)) {
                    $dirs['app:' . $name] = $candidate;
                    break;
                }
            }
        }

        return $dirs;
    }

    /**
     * 解析所有路由来源（标签 => 文件路径），全部自动发现，无需配置开关。
     *
     * 顺序：
     *  - 主应用 app/routes.php（单一入口文件，标签 app）；
     *  - app/routes/*.php 全部文件（每个文件一个来源，标签 routes:<filename>）；
     *  - 子应用 app/{App}/routes.php 与 app/{App}/routes/*.php（标签 app:<App>[:<file>]）；
     *  - config/routes.php 的 sources 额外声明；
     *  - plugins/<name>/routes.php 自动发现（标签 plugin:<name>）。
     *
     * @return array<string, string>
     */
    private function resolveRouteSources(): array
    {
        $base = $this->config('path.base');
        $sources = [];

        $appRoutes = $base . '/app/routes.php';
        if (is_file($appRoutes)) {
            $sources['app'] = $appRoutes;
        }

        // 自动 glob app/routes/*.php：新增路由文件即生效，无需登记。
        // 跳过约定入口文件 app/routes.php（已由上方 'app' 标签加载，避免重复注册）。
        foreach (glob($base . '/app/routes/*.php') ?: [] as $file) {
            if (basename((string) $file) === 'routes.php') {
                continue;
            }
            $label = 'routes:' . basename((string) $file, '.php');
            $sources[$label] = $file;
        }

        // 多应用自动发现：app/{App}/routes.php + app/{App}/routes/*.php（零开关）。
        foreach ($this->discoverApplications() as $appDir) {
            $appName = basename((string) $appDir);
            $appRoutesFile = $appDir . '/routes.php';
            if (is_file($appRoutesFile)) {
                $sources['app:' . $appName] = $appRoutesFile;
            }
            foreach (glob($appDir . '/routes/*.php') ?: [] as $file) {
                if (basename((string) $file) === 'routes.php') {
                    continue;
                }
                $label = 'app:' . $appName . ':' . basename((string) $file, '.php');
                $sources[$label] = $file;
            }
        }

        /** @var array<string, string> $extra */
        $extra = (array) $this->config('routes.sources', []);
        foreach ($extra as $key => $file) {
            $sources[$key] = $file;
        }

        // 插件路由自动发现（目录存在即纳入）。
        $pluginsDir = $base . '/plugins';
        if (is_dir($pluginsDir)) {
            foreach (glob($pluginsDir . '/*', GLOB_ONLYDIR) ?: [] as $pluginDir) {
                $pluginRoutes = $pluginDir . '/routes.php';
                if (is_file($pluginRoutes)) {
                    $sources['plugin:' . basename($pluginDir)] = $pluginRoutes;
                }
            }
        }

        return $sources;
    }

    /**
     * 加载单个路由文件，并为其中新增的路由打上来源标签。
     */
    private function loadRoutes(App $app, string $file, string $label, RouteRegistry $registry): void
    {
        if (!is_file($file)) {
            return;
        }

        $before = $app->getRouter()->getRoutes();
        $register = require $file;
        if ($register instanceof \Closure) {
            $register($app);
        }
        $after = $app->getRouter()->getRoutes();

        for ($i = count($before); $i < count($after); $i++) {
            $registry->tag($after[$i], $label);
        }
    }

    /**
     * 注册框架内置健康探针：/health、/health/live、/health/ready、/ping。
     *
     * 设计立场：
     *  - 始终存在，不依赖用户路由；便于编排系统（k8s/LB）做存活与就绪检查。
     *  - /health/live：liveness（存活）。进程能响应即视为存活，永远 200，不含外部依赖探测，
     *    用于重启判定（k8s livenessProbe）。
     *  - /health/ready：readiness（就绪）。经 {@see HealthChecker} 探测所有启用组件（db/cache/queue/
     *    自定义）；任一 error 即 503，使流量在依赖未就绪时被摘除（k8s readinessProbe）。
     *  - /health：聚合视图，返回版本/PHP/环境/时间 + components 明细，便于人工巡检。
     *  - /ping：极简 pong，轻量探活。
     */
    private function registerHealthEndpoints(App $app): void
    {
        $app->get('/health/live', static fn() => Resp::json([
            'status' => 'ok',
        ]));

        $app->get('/health/ready', function () {
            // 排空期感知（H3）：收到 SIGTERM 后 readiness 必须 503 使 k8s Service 摘流，
            // 否则 10-30s 窗口内仍被调度流量导致 502。
            try {
                $graceful = $this->container->get(\Kode\Framework\Server\GracefulShutdown::class);
                if ($graceful instanceof \Kode\Framework\Server\GracefulShutdown && $graceful->isShuttingDown()) {
                    return Resp::json(['status' => 'degraded', 'reason' => 'shutting_down', 'checks' => []], 503);
                }
            } catch (\Throwable) {
            }
            $result = $this->healthChecker()->check();
            $status = $result['healthy'] ? 200 : 503;

            return Resp::json([
                'status'  => $result['healthy'] ? 'ok' : 'degraded',
                'checks'  => $result['checks'],
            ], $status);
        });

        $app->get('/health', function () {
            // 走容器单例（与 /health/ready 同源）：每请求 new 会丢失单例上注册的自定义探针，
            // 且有重复构建开销。
            $result = $this->healthChecker()->check();

            return Resp::json([
                'status'      => 'ok',
                'service'     => Application::getInstance()?->config()->get('app.name', 'kode-app'),
                'version'     => Application::VERSION,
                'php'         => PHP_VERSION,
                'env'         => Application::getInstance()?->config()->get('app.env', 'local'),
                'time'        => date('c'),
                'components'  => $result['checks'],
            ]);
        });

        $app->get('/ping', static fn() => Resp::json(['pong' => true]));
    }

    /**
     * 取健康探针聚合器单例（由 HealthServiceProvider 绑定，已注入容器 + 事件派发闭包）。
     */
    private function healthChecker(): HealthChecker
    {
        return resolve(HealthChecker::class);
    }
}
