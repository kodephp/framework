<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Attributes;

use Attribute;

/**
 * 标记一个类为 HTTP 控制器（属性路由模型）。
 *
 * 配合方法级 {@see Route}/{@see Get}/{@see Post} 等属性，框架会在启动时
 * 自动扫描并注册路由——无需在 routes.php 里逐条手写。
 *
 * 这是「多应用自动路由匹配」的落地方式：你只需在控制器类/方法上声明意图，
 * 框架负责发现与装配；同时方法上的 {@see Route::name} 仍支持「可指定命名方法」
 * （命名路由反向生成 URL）。两种模型并存、互不冲突。
 *
 * @example
 * #[Controller(prefix: '/api/users', middleware: [AuthMiddleware::class])]
 * final class UserController extends Controller { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Controller
{
    /**
     * @param string $prefix     该类下所有路由的统一前缀
     * @param array  $middleware 该类下所有路由统一挂载的中间件
     */
    public function __construct(
        public readonly string $prefix = '',
        public readonly array $middleware = [],
    ) {
    }
}
