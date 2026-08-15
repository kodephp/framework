<?php

declare(strict_types=1);

namespace Kode\Framework\Http;

use Kode\Http\Routing\RouteResult;
use Kode\Http\Routing\Router;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 路由匹配共享 trait：让各路由感知型中间件复用 {@see RouteResolver} 的「单次请求一次匹配」缓存。
 *
 * 中间件在构造函数持有 ?RouteResolver（由 HttpServiceProvider 注入，单测构造时为 null 走旧路径）；
 * 本 trait 的 resolveRoute() 优先用 resolver（命中跨中间件缓存），否则回退到各自持有的 Router 直匹配，
 * 保证单测（无 resolver）与生产（有 resolver）行为一致。
 */
trait RouteMatchTrait
{
    /**
     * 解析当前请求命中的路由（跨中间件缓存命中）。
     *
     * @return array{0: ServerRequestInterface, 1: ?RouteResult}
     *   返回 [augmentedRequest, matched|null]；augmentedRequest 已含缓存属性，须继续向下传递。
     */
    private function resolveRoute(ServerRequestInterface $request): array
    {
        if (isset($this->resolver) && $this->resolver !== null) {
            return $this->resolver->resolve($request);
        }

        if (isset($this->router) && $this->router !== null) {
            $matched = $this->router->match($request->getMethod(), $request->getUri()->getPath());

            return [$request, $matched];
        }

        return [$request, null];
    }
}
