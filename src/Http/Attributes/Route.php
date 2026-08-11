<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Attributes;

use Attribute;

/**
 * 在控制器方法上声明一条路由（属性路由模型）。
 *
 * 与 routes.php 中的显式注册等价，但写法更贴近方法本身：
 *
 * @example
 * #[Route(['GET', 'HEAD'], '/users/{id:\\d+}', name: 'user.show', middleware: [CacheMiddleware::class])]
 * public function show($req) { ... }
 *
 * 便捷属性 {@see Get}/{@see Post}/{@see Put}/{@see Delete}/{@see Patch}/{@see Any}
 * 是本案的语法糖，仅固定 HTTP 方法。
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Route
{
    /**
     * @param string|string[] $methods    HTTP 方法（'GET' 或 ['GET','HEAD']）
     * @param string          $path       路由路径（相对类前缀；'/' 表示根）
     * @param string|null     $name       命名路由（用于 route() 反向生成 URL）
     * @param array           $middleware 仅作用于本条路由的中间件
     */
    public function __construct(
        public readonly string|array $methods = 'GET',
        public readonly string $path = '',
        public readonly ?string $name = null,
        public readonly array $middleware = [],
    ) {
    }

    /**
     * 便捷属性复用的默认方法集合（子类覆盖）。
     *
     * @return string|string[]
     */
    public function methods(): string|array
    {
        return $this->methods;
    }
}
