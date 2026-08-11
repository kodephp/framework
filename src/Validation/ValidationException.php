<?php

declare(strict_types=1);

namespace Kode\Framework\Validation;

use RuntimeException;

/**
 * 参数校验失败异常，由 Controller::validate() 抛出，
 * 由 HTTP 层的异常处理器转换为 422 JSON。
 *
 * @property-read array<int, array{field:string, message:string}> $errors
 */
final class ValidationException extends RuntimeException
{
    /**
     * @param array<int, array{field:string, message:string}> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('参数校验失败', 422);
    }

    /**
     * @return array<int, array{field:string, message:string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
