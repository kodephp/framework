<?php

declare(strict_types=1);

namespace Kode\Framework\Tenant\Storage;

use Kode\Exception\KodeException;
use Kode\Framework\Tenant\TenantContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 租户存储隔离中间件（PSR-15，运行于 TenantMiddleware 内层）。
 *
 * 依赖 TenantMiddleware 已在请求级 scope 写入租户标识（TenantContext::id() 可见）。
 * 流程：
 *  1) 读取当前租户标识；为 null 则直接放行（不隔离）；
 *  2) 调用 TenantStorageManager::boot() 切换默认 DB 连接（返回切换前连接名）；
 *  3) 处理下游（handler 内的查询自动落到租户连接）；
 *  4) finally 中 restore() 把默认连接恢复，绝不跨请求串扰；
 *  5) 若 on_missing=abort 且租户无映射，解析器抛 TenantStorageUnresolved，
 *     此处转为标准 404（KodeException::notFound，由 ExceptionMiddleware 渲染结构化响应）。
 */
final class TenantStorageMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TenantStorageManager $manager,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $tenantId = TenantContext::id();
        $previous = null;

        if ($tenantId !== null) {
            try {
                $previous = $this->manager->boot($tenantId);
            } catch (TenantStorageUnresolved $e) {
                throw KodeException::notFound($e->getMessage());
            }
        }

        try {
            return $handler->handle($request);
        } finally {
            if ($previous !== null) {
                $this->manager->restore($previous);
            }
        }
    }
}
