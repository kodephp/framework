<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Application;
use Kode\Framework\Http\Middleware\CorsMiddleware;
use Kode\Framework\Http\Middleware\LocaleMiddleware;
use Kode\Framework\Http\Middleware\RequestIdMiddleware;
use Kode\Framework\Http\Middleware\SecurityHeadersMiddleware;
use Kode\Framework\Http\RouteRegistry;
use Kode\Framework\Providers\ServiceProvider;
use Kode\Framework\Http\Resp;
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

        // ---- 框架内置探针（适配 k8s / 负载均衡健康检查）----
        $this->registerHealthEndpoints($app);

        // 加载路由（支持多来源 + 插件发现，每条路由打来源标签供 route:list 使用）。
        /** @var RouteRegistry $registry */
        $registry = $this->container->get(RouteRegistry::class);
        foreach ($this->resolveRouteSources() as $label => $file) {
            $this->loadRoutes($app, $file, $label, $registry);
        }
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
