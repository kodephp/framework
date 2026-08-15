<?php

declare(strict_types=1);

namespace Kode\Framework\Http;

use Kode\Http\Routing\RouteResult;
use Kode\Http\Routing\Router;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 路由匹配共享器（框架内薄工具，不改动 vendor/kode/http）。
 *
 * 「全局中间件按路由属性按需挂载」需要每个路由感知型中间件知道「当前请求命中了哪条路由」，
 * 而 kode/http 的全局中间件在路由匹配之前运行，只能自己调用 router->match() 取 Route 对象。
 * 若每个中间件各自 match() 一遍整张路由表，高路由数下开销可观（N 次 → 每次请求 N 次全表扫描）。
 *
 * 本解析器把 match() 在「单次请求内」只执行一次：结果（RouteResult）挂在 ServerRequest 的
 * 属性上，同一请求流经后续中间件时直接命中缓存。请求对象随请求结束而销毁，无跨请求泄漏。
 *
 * 中间件管道顺序上 RateLimitMiddleware 最先运行，由它完成唯一一次 match() 并写入属性，
 * 其后 CircuitBreaker / Idempotency / Retry / Feature / Csrf 全部命中缓存 → 全请求仅 1 次匹配。
 */
final class RouteResolver
{
    /** 缓存命中标记用的请求属性键（请求级，随请求对象销毁）。 */
    public const ATTR = '__kode_matched_route__';

    public function __construct(private readonly Router $router)
    {
    }

    /**
     * 取（并在请求上缓存）当前请求的匹配结果。
     *
     * @return array{0: ServerRequestInterface, 1: RouteResult}
     *   返回 [augmentedRequest, routeResult]；调用方必须把 augmentedRequest 继续向下传递，
     *   以便后续中间件命中缓存（同一 ServerRequest 对象流经整条管线）。
     */
    public function resolve(ServerRequestInterface $request): array
    {
        $cached = $request->getAttribute(self::ATTR);
        if ($cached instanceof RouteResult) {
            return [$request, $cached];
        }

        $result = $this->router->match($request->getMethod(), $request->getUri()->getPath());

        return [$request->withAttribute(self::ATTR, $result), $result];
    }
}
