<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Attributes\Reader;
use Kode\Framework\Application;
use Kode\Framework\Http\ControllerScanner;
use Kode\Framework\Http\Middleware\CorsMiddleware;
use Kode\Framework\Http\Middleware\LocaleMiddleware;
use Kode\Framework\Http\Middleware\RequestIdMiddleware;
use Kode\Framework\Http\Middleware\SecurityHeadersMiddleware;
use Kode\Framework\Http\RouteRegistry;
use Kode\Framework\Providers\ServiceProvider;
use Kode\Framework\Http\Resp;
use Kode\Framework\Http\RateLimit\LimiterFactory;
use Kode\Framework\Http\RateLimit\RateLimitAttributeReader;
use Kode\Framework\Http\Middleware\RateLimitMiddleware;
use Kode\Framework\Translation\Translator;
use Kode\Http\App;
use Kode\Framework\Validation\ValidationException;
use Psr\Log\LoggerInterface;

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

        // 属性路由扫描器依赖（kode/attributes Reader，无缓存即可）。
        $this->container->singleton(Reader::class, fn(): Reader => new Reader());
    }

    public function boot(): void
    {
        /** @var App $app */
        $app = $this->container->get(App::class);
        $debug = (bool) $this->config('app.debug', false);

        /** @var LoggerInterface $logger */
        $logger = $this->container->get(LoggerInterface::class);

        // 全局异常：记录日志，结构化返回 JSON（校验失败 → 422）。
        $app->getErrorHandler()->onError(500, function (\Throwable $e) use ($logger, $debug) {
            if ($e instanceof ValidationException) {
                return Resp::fail('参数校验失败', 'E422', 422, ['errors' => $e->errors()]);
            }

            $logger->error($e->getMessage(), ['exception' => $e]);

            $data = $debug ? ['trace' => $e->getTraceAsString()] : [];
            $msg = $debug ? $e->getMessage() : 'Internal Server Error';

            return Resp::fail($msg, 'E500', 500, $data);
        });

        // 友好的 404。
        $app->notFound(fn() => Resp::fail('Not Found', 'E404', 404));

        // ---- 全局中间件链（顺序：追踪 → CORS → 安全头）----
        // 这些是企业级 API 基础设施，默认开启、配置驱动，可按需关闭。
        $app->use(new RequestIdMiddleware([
            'enabled' => !empty($this->config('security.request_id', true)),
            'request_id_allow_client' => !empty($this->config('security.request_id_allow_client', true)),
        ]));

        $app->use(new CorsMiddleware((array) $this->config('cors', [])));

        $app->use(new SecurityHeadersMiddleware((array) $this->config('security', [])));

        // 国际化：Accept-Language 自动选语种（默认开启，config/locale.php 可关）。
        if (!empty($this->config('locale.enabled', true))) {
            /** @var Translator $translator */
            $translator = $this->container->get(Translator::class);
            $app->use(new LocaleMiddleware((array) $this->config('locale', []), $translator));
        }

        // 全局限流：按路由上的 #[RateLimit] 细粒度限流，否则回落全局默认。
        // driver=redis 时即为分布式限流。开关见 config/limiting.enabled。
        if (!empty($this->config('limiting.enabled', true))) {
            /** @var LimiterFactory $factory */
            $factory = $this->container->get(LimiterFactory::class);
            /** @var RouteRegistry $registry */
            $registry = $this->container->get(RouteRegistry::class);

            $app->use(new RateLimitMiddleware(
                $app->getRouter(),
                $registry,
                $factory,
                (array) $this->config('limiting', [])
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

            /** @var list<\Kode\Framework\Http\RateLimit\RateLimitRule> $rules */
            $rules = $reader->read($class, $method);
            $registry->tagRateLimits($route, $rules);
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
     */
    private function scanAttributeRoutes(App $app, RouteRegistry $registry): void
    {
        if (empty($this->config('routes.attributes.enabled', true))) {
            return;
        }

        /** @var Reader $reader */
        $reader = $this->container->get(Reader::class);
        $scanner = new ControllerScanner($app, $reader, $registry);

        /** @var array<string, string> $dirs */
        $dirs = (array) $this->config('routes.attributes.controllers', []);
        $dirs = $this->withPluginControllerDirs($dirs);
        $scanner->scan($dirs);
    }

    /**
     * 开启插件发现时，把 plugins/<name>/src/Controllers 纳入属性扫描。
     *
     * @param array<string, string> $dirs
     * @return array<string, string>
     */
    private function withPluginControllerDirs(array $dirs): array
    {
        if (empty($this->config('routes.discover_plugins', false))) {
            return $dirs;
        }

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
     * 解析所有路由来源（标签 => 文件路径）。
     *
     * 顺序：主应用 app/routes.php → config/routes.php 声明的 sources →
     * （可选）plugins/<name>/routes.php 自动发现。
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

        /** @var array<string, string> $extra */
        $extra = (array) $this->config('routes.sources', []);
        foreach ($extra as $key => $file) {
            $sources[$key] = $file;
        }

        if (!empty($this->config('routes.discover_plugins', false))) {
            $pluginsDir = $base . '/plugins';
            if (is_dir($pluginsDir)) {
                foreach (glob($pluginsDir . '/*', GLOB_ONLYDIR) ?: [] as $pluginDir) {
                    $pluginRoutes = $pluginDir . '/routes.php';
                    if (is_file($pluginRoutes)) {
                        $sources['plugin:' . basename($pluginDir)] = $pluginRoutes;
                    }
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
     * 注册框架内置健康探针：/health、/ping。
     *
     * 设计立场：
     *  - 始终存在，不依赖用户路由；便于编排系统（k8s/LB）做存活与就绪检查。
     *  - /health 返回结构化 JSON（版本、PHP、环境、时间），可用于就绪（ready）判断；
     *  - /ping 返回极简 pong，用于轻量存活（liveness）探测。
     */
    private function registerHealthEndpoints(App $app): void
    {
        $app->get('/health', static function () {
            return Resp::ok([
                'status'  => 'ok',
                'service' => Application::getInstance()?->config()->get('app.name', 'kode-app'),
                'version' => Application::VERSION,
                'php'     => PHP_VERSION,
                'env'     => Application::getInstance()?->config()->get('app.env', 'local'),
                'time'    => date('c'),
            ], 'healthy');
        });

        $app->get('/ping', static fn() => Resp::ok(['pong' => true], 'pong'));
    }
}
