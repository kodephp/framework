<?php

declare(strict_types=1);

namespace Kode\Framework\Facades;

use Kode\Core\Facade;
use Kode\Framework\Auth\JwtGuard;

/**
 * JWT 门面：Jwt::issue(['uid'=>1]) / Jwt::authenticate($token)。
 */
final class Jwt extends Facade
{
    protected static function id(): string
    {
        return JwtGuard::class;
    }
}
