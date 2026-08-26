<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Attributes\Reader;
use Kode\Framework\Http\ControllerScanner;
use Kode\Framework\Http\RouteRegistry;
use Kode\Http\App;
use PHPUnit\Framework\TestCase;

/**
 * 控制器方法可见性过滤：仅 public 方法可注册为路由（路由处理器以
 * `resolve($class)->{$method}()` 调用，非 public 会因作用域抛 Error；
 * 同时保证 API 文档只导出真实可路由的 public 方法）。
 */
final class ControllerVisibilityTest extends TestCase
{
    public function testOnlyPublicMethodsAreRegistered(): void
    {
        $app = new App();
        $registry = new RouteRegistry();
        $scanner = new ControllerScanner($app, new Reader(), $registry);

        // 目录含 VisibilityController（public/private/protected 三个路由方法）与
        // RateLimitedController（无路由属性，应被跳过）。
        $scanner->scan(['fixture' => __DIR__ . '/Fixtures/Controllers']);

        $patterns = array_map(
            static fn($route): string => $route->getPattern(),
            $app->getRouter()->getRoutes()
        );

        // public 方法正常注册。
        self::assertContains('/visibility/public', $patterns, 'public 方法应注册为路由');

        // 非 public 方法不得注册（避免作用域运行时错误与文档泄漏）。
        self::assertNotContains('/visibility/secret', $patterns, 'private 方法不得注册为路由');
        self::assertNotContains('/visibility/internal', $patterns, 'protected 方法不得注册为路由');

        // 无路由属性的控制器类不影响扫描。
        self::assertNotContains('/rate-limited', $patterns);
    }
}