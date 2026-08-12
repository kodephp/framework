<?php

/*
 * Kode Framework 全局辅助函数
 *
 * 这些函数无需 use 即可在任意位置调用，统一从 Application 容器解析服务，
 * 让业务代码保持「无框架感」的简洁写法。
 */

use Kode\Core\App;
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
        return app()->make($id, $parameters);
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
     *   process()->register(new App\Process\HeartbeatWorker);
     *   process()->dryRun();        // 无 fork 验证逻辑
     *   process()->start();         // 真正 fork 常驻进程（CLI + pcntl）
     */
    function process(): object
    {
        return resolve('process.manager');
    }
}
