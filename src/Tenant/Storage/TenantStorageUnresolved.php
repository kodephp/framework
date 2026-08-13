<?php

declare(strict_types=1);

namespace Kode\Framework\Tenant\Storage;

use RuntimeException;

/**
 * 租户存储未解析异常（on_missing = abort 时抛出）。
 *
 * 由 TenantStorageMiddleware 捕获并转为 KodeException::notFound（结构化 404），
 * 使「未登记租户一律拒绝」成为标准 404，而非 500。
 */
final class TenantStorageUnresolved extends RuntimeException
{
    public function __construct(string $tenantId)
    {
        parent::__construct("租户 [{$tenantId}] 未配置可用存储隔离");
    }
}
