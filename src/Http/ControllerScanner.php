<?php

declare(strict_types=1);

namespace Kode\Framework\Http;

use Kode\Attributes\MetaList;
use Kode\Attributes\Reader;
use Kode\Attributes\Scanner;
use Kode\Framework\ApiDoc\Attributes\OpenApi;
use Kode\Framework\Feature\FeatureAttributeReader;
use Kode\Framework\Feature\FeatureRegistry;
use Kode\Framework\Http\Attributes\Controller;
use Kode\Framework\Http\Attributes\Route;
use Kode\Framework\Http\RateLimit\RateLimitAttributeReader;
use Kode\Framework\Idempotency\IdempotencyAttributeReader;
use Kode\Framework\Resilience\CircuitBreakerAttributeReader;
use Kode\Framework\Resilience\RetryAttributeReader;
use Kode\Framework\Security\Csrf\CsrfAttributeReader;
use Kode\Http\App;
use ReflectionMethod;

/**
 * 控制器扫描器（属性路由模型）。
 *
 * 启动时扫描给定目录下的控制器类：
 *  - 类级 #[Controller(prefix, middleware)] 提供统一前缀与中间件；
 *  - 方法级 #[Route] / #[Get] / #[Post] ... 声明具体路由。
 *
 * 无需在 routes.php 里逐条手写——这就是「多应用自动路由匹配」。
 * 同时方法上的 name 仍支持「可指定命名方法」（route() 反向生成 URL）。
 *
 * 与 routes.php（显式命名路由）并存：属性路由默认先注册，routes.php 的
 * 显式条目可覆盖同名路径，二者互不冲突。
 */
final class ControllerScanner
{
    public function __construct(
        private readonly App $app,
        private readonly Reader $reader,
        private readonly RouteRegistry $registry,
        private readonly RateLimitAttributeReader $rateLimitReader = new RateLimitAttributeReader(),
        private readonly CsrfAttributeReader $csrfReader = new CsrfAttributeReader(),
        private readonly CircuitBreakerAttributeReader $circuitBreakerReader = new CircuitBreakerAttributeReader(),
        private readonly RetryAttributeReader $retryReader = new RetryAttributeReader(),
        private readonly IdempotencyAttributeReader $idempotencyReader = new IdempotencyAttributeReader(),
        private readonly FeatureRegistry $featureRegistry = new FeatureRegistry(),
        private readonly FeatureAttributeReader $featureReader = new FeatureAttributeReader(),
    ) {
    }

    /**
     * 扫描一批目录并注册路由。
     *
     * @param array<string, string> $dirs  key 为来源标签（如 'app' / 'plugin:blog'），value 为目录绝对路径
     */
    public function scan(array $dirs): void
    {
        foreach ($dirs as $source => $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $this->scanDir($dir, (string) $source);
        }
    }

    private function scanDir(string $dir, string $source): void
    {
        $scanner = new Scanner($this->reader, $dir);

        foreach ($scanner->classes($dir) as $class) {
            if (!class_exists($class)) {
                continue;
            }

            $controllerAttr = $this->reader->getClassAttrs($class)->get(Controller::class);
            $prefix = '';
            $classMiddleware = [];
            if ($controllerAttr !== null) {
                /** @var Controller $ctrl */
                $ctrl = $controllerAttr->getInstance();
                $prefix = $ctrl->prefix;
                $classMiddleware = $ctrl->middleware;
            }

            $methodAttrs = $this->reader->getAllMethodAttrs($class);
            foreach ($methodAttrs as $method => $metas) {
                $routeMeta = $metas->get(Route::class);
                if ($routeMeta === null) {
                    continue;
                }
                /** @var Route $attr */
                $attr = $routeMeta->getInstance();
                $this->register($class, $method, $attr, $prefix, $classMiddleware, $source, $metas);
            }
        }
    }

    private function register(
        string $class,
        string $method,
        Route $attr,
        string $prefix,
        array $classMiddleware,
        string $source,
        MetaList $metas,
    ): void {
        $path = $this->fullPath($prefix, $attr->path);
        $handler = fn($req) => resolve($class)->{$method}($req);

        $route = $this->app->route($attr->methods, $path, $handler);
        $this->registry->tag($route, $source);

        // 声明式限流：类级规则（对所有方法生效）+ 本方法级规则（叠加）。
        /** @var list<\Kode\Limiting\Attribute\RateLimit> $rules */
        $rules = $this->rateLimitReader->read($class, $method);
        $this->registry->tagRateLimits($route, $rules);

        // 声明式 CSRF 防护：类级（全部方法）/ 方法级（个别写操作）标记，供全局 CsrfMiddleware 命中。
        if ($this->csrfReader->isPresent($class, $method)) {
            $this->registry->tagCsrf($route, true);
        }

        // 声明式边缘熔断：类级/方法级 #[CircuitBreaker] 标记，供全局 CircuitBreakerMiddleware 命中。
        if ($this->circuitBreakerReader->isPresent($class, $method)) {
            $this->registry->tagCircuitBreaker($route, true);
        }

        // 声明式 HTTP 重试：类级/方法级 #[Retry] 标记，供全局 RetryMiddleware 命中。
        if ($this->retryReader->isPresent($class, $method)) {
            $this->registry->tagRetry($route, true);
        }

        // 声明式幂等防护：类级/方法级 #[Idempotency] 标记，供全局 IdempotencyMiddleware 命中。
        if ($this->idempotencyReader->isPresent($class, $method)) {
            $this->registry->tagIdempotency($route, true);
        }

        if ($attr->name !== null) {
            $route->name($attr->name);
        }

        $middleware = array_values(array_unique([...$classMiddleware, ...$attr->middleware]));
        foreach ($middleware as $mw) {
            $route->middleware($mw);
        }

        // OpenAPI：捕获方法上的 #[OpenApi] 补充片段，供 ApiDoc 生成器读取。
        $openApiMeta = $metas->get(OpenApi::class);
        if ($openApiMeta !== null) {
            /** @var OpenApi $openApi */
            $openApi = $openApiMeta->getInstance();
            $this->registry->tagOpenApi($route, $openApi);
        }

        // 声明式功能开关：类级 #[Feature] 默认、方法级覆盖。
        $feature = $this->featureReader->read($class, $method);
        if ($feature !== null) {
            $this->featureRegistry->tag($route, $feature['flag'], $feature['fallback']);
        }
    }

    private function fullPath(string $prefix, string $path): string
    {
        if ($path === '' || $path === '/') {
            return $prefix === '' ? '/' : $prefix;
        }

        return rtrim($prefix, '/') . '/' . ltrim($path, '/');
    }
}
