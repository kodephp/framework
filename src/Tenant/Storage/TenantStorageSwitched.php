<?php

declare(strict_types=1);

namespace Kode\Framework\Tenant\Storage;

/**
 * 租户存储连接已切换事件。
 *
 * 在请求级把默认 DB 连接切换到某租户连接后派发（成功分支）。
 * 可接指标 / 审计 / 调试（例如统计各租户连接使用频次）。
 */
final class TenantStorageSwitched
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $connectionName,
    ) {
    }
}
