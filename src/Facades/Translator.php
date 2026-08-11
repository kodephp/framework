<?php

declare(strict_types=1);

namespace Kode\Framework\Facades;

use Kode\Core\Facade;

/**
 * 翻译门面：Translator::trans('key', ['id' => 1])。
 *
 * 解析目标为 Kode\Framework\Translation\Translator（经容器单例）。
 */
final class Translator extends Facade
{
    protected static function id(): string
    {
        return \Kode\Framework\Translation\Translator::class;
    }
}
