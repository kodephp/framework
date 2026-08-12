<?php

declare(strict_types=1);

namespace Kode\Framework\Facades;

use Kode\Core\Facade;
use Kode\Exception\ExceptionManager;

/**
 * 异常管理门面：exception()->respond($e) / format($e) / report($e)。
 *
 * 底层即 kode/exception 的 ExceptionManager（由 ExceptionServiceProvider 构建）。
 */
final class Exception extends Facade
{
    protected static function id(): string
    {
        return ExceptionManager::class;
    }
}
