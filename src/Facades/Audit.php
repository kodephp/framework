<?php

declare(strict_types=1);

namespace Kode\Framework\Facades;

use Kode\Core\Facade;
use Kode\Framework\Security\Audit\AuditService;

/**
 * 审计门面：Audit::record(...)（通常由审计中间件自动调用，业务亦可手动补记）。
 */
final class Audit extends Facade
{
    protected static function id(): string
    {
        return AuditService::class;
    }
}
