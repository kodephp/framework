<?php

declare(strict_types=1);

namespace Kode\Framework\Facades;

use Kode\Core\Facade;

/**
 * Schema 门面：Schema::create('users', fn ($t) => ...)。
 */
final class Schema extends Facade
{
    protected static function id(): string
    {
        return \Kode\Framework\Database\Schema::class;
    }
}
