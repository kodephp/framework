<?php

declare(strict_types=1);

namespace Kode\Framework\Facades;

use Kode\Core\Facade;
use Psr\Log\LoggerInterface;

/**
 * 日志门面：Log::info('msg', [...])。
 */
final class Log extends Facade
{
    protected static function id(): string
    {
        return LoggerInterface::class;
    }
}
