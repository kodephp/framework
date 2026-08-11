<?php

declare(strict_types=1);

namespace Kode\Framework\Facades;

use Kode\Core\Facade;
use Kode\Framework\Validation\Validator as ValidationValidator;

/**
 * 验证门面：Validator::validate($data, $rules)。
 */
final class Validator extends Facade
{
    protected static function id(): string
    {
        return ValidationValidator::class;
    }
}
