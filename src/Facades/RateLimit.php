<?php

declare(strict_types=1);

namespace Kode\Framework\Facades;

use Kode\Core\Facade;
use Kode\Limiting\Limiter;

/**
 * 限流门面：RateLimit::consume('key') / check() / allow() / reset()。
 */
final class RateLimit extends Facade
{
    protected static function id(): string
    {
        return Limiter::class;
    }
}
