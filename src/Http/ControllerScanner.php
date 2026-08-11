<?php

declare(strict_types=1);

namespace Kode\Framework\Http;

use Kode\Attributes\Reader;
use Kode\Attributes\Scanner;
use Kode\Framework\Http\Attributes\Controller;
use Kode\Framework\Http\Attributes\Route;
use Kode\Framework\Http\RateLimit\RateLimitAttributeReader;
use Kode\Framework\Http\RateLimit\RateLimitRule;
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
                $this->register($class, $method, $attr, $prefix, $classMiddleware, $source);
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
    ): void {
        $path = $this->fullPath($prefix, $attr->path);
        $handler = fn($req) => resolve($class)->{$method}($req);

        $route = $this->app->route($attr->methods, $path, $handler);
        $this->registry->tag($route, $source);

        // 声明式限流：类级规则（对所有方法生效）+ 本方法级规则（叠加）。
        /** @var list<RateLimitRule> $rules */
        $rules = $this->rateLimitReader->read($class, $method);
        $this->registry->tagRateLimits($route, $rules);

        if ($attr->name !== null) {
            $route->name($attr->name);
        }

        $middleware = array_values(array_unique([...$classMiddleware, ...$attr->middleware]));
        foreach ($middleware as $mw) {
            $route->middleware($mw);
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
