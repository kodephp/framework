<?php

declare(strict_types=1);

namespace Kode\Framework\Tenant;

use Kode\Context\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 租户解析中间件（PSR-15）。
 *
 * 在请求入口解析租户，并通过 Context::runWith 建立「每请求隔离 scope」写入租户标识，
 * 下游中间件 / 控制器 / 助手 tenant() 均可在该 scope 内取到。请求结束后 scope 自动出栈，
 * 下一个请求重新解析，绝不跨请求串扰（框架与 kode/http 均未在请求外层包 Context::run，故必须在此隔离）。
 */
final class TenantMiddleware implements MiddlewareInterface
{
    /**
     * @param TenantResolver|null $resolver   租户解析器；为 null 则直接用 default（不解析）。
     * @param string|null        $default     解析失败 / 无解析器时的回退租户（null = 无默认）。
     */
    public function __construct(
        private readonly ?TenantResolver $resolver = null,
        private readonly ?string $default = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $id = $this->resolver?->resolve($request) ?? $this->default;

        // 每请求隔离 scope：仅在此 scope 内 tenant() 可见，请求结束自动出栈。
        return Context::runWith([TenantContext::KEY => $id], function () use ($request, $handler, $id): ResponseInterface {
            // 同时挂到请求属性，便于需要 PSR 访问的handler直接取用（不依赖 Context）。
            return $handler->handle($request->withAttribute('tenant', $id));
        });
    }
}
