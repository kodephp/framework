<?php

declare(strict_types=1);

namespace Kode\Framework\Facades;

use Kode\Core\Facade;
use Kode\Framework\ApiDoc\OpenApiGenerator;

/**
 * API 文档门面：ApiDoc::generate() / ApiDoc::toJson()。
 */
final class ApiDoc extends Facade
{
    protected static function id(): string
    {
        return OpenApiGenerator::class;
    }
}
