<?php

/*
 * 路由定义
 *
 * 返回闭包，接收 Kode\Http\App 实例，向其中注册路由。
 * 处理器既可写成闭包，也可指向控制器方法（经容器解析，支持依赖注入）：
 *   fn($req) => resolve(\App\Http\Controllers\UserController::class)->show($req)
 * 路由参数通过 Kode\Http\Request::param('id') 获取。
 */

use Kode\Http\App;
use Kode\Http\Kode;
use Kode\Framework\Http\Middleware\AuthMiddleware;
use Kode\Framework\Http\Middleware\RateLimitMiddleware;

return function (App $app): void {
    // 健康检查 / 元信息
    $app->get('/', fn(): array => [
        'framework' => 'kode',
        'http' => Kode::VERSION,
        'time' => date('c'),
    ]);

    // 简单 GET（路由参数 + 命名路由）
    $app->get('/users/{id:\d+}', fn($req) => resolve(\App\Http\Controllers\UserController::class)->show($req))
        ->name('user.show');

    // 创建用户（参数校验 + 统一成功响应）
    $app->post('/users', fn($req) => resolve(\App\Http\Controllers\UserController::class)->store($req));

    // 受 JWT 保护的接口
    $app->get('/me', fn($req) => resolve(\App\Http\Controllers\UserController::class)->me($req))
        ->middleware(new AuthMiddleware());

    // 登录签发 JWT
    $app->post('/auth/login', fn($req) => resolve(\App\Http\Controllers\AuthController::class)->login($req));

    // 缓存 / 事件 / 日志 集成示例
    $app->get('/demo/cache', fn() => resolve(\App\Http\Controllers\DemoController::class)->cache());
    $app->get('/demo/event', fn() => resolve(\App\Http\Controllers\DemoController::class)->fireEvent());
    $app->get('/demo/aop', fn() => resolve(\App\Http\Controllers\DemoController::class)->aop());
    $app->get('/demo/concurrent', fn() => resolve(\App\Http\Controllers\DemoController::class)->concurrent());

    // 限流 / 数据库 集成示例
    $app->get('/demo/ratelimit', fn($req) => resolve(\App\Http\Controllers\DemoController::class)->rateLimit($req));
    $app->get('/demo/db', fn() => resolve(\App\Http\Controllers\DemoController::class)->db());
    // 挂限流中间件：快速连点可见 429 + RateLimit 响应头
    $app->get('/demo/rate-limit', fn($req) => resolve(\App\Http\Controllers\DemoController::class)->rateLimit($req))
        ->middleware(new RateLimitMiddleware());

    // HTTP 客户端 / 消息总线 集成示例
    $app->get('/demo/http', fn() => resolve(\App\Http\Controllers\DemoController::class)->httpClient());
    $app->get('/demo/message', fn() => resolve(\App\Http\Controllers\DemoController::class)->message());

    // 熔断器 / 国际化 集成示例
    $app->get('/demo/breaker', fn() => resolve(\App\Http\Controllers\DemoController::class)->breakerDemo());
    $app->get('/demo/i18n', fn() => resolve(\App\Http\Controllers\DemoController::class)->i18nDemo());
};
