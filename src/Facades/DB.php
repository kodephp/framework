<?php

declare(strict_types=1);

namespace Kode\Framework\Facades;

use Kode\Core\Facade;
use Kode\Framework\Database\DatabaseProxy;

/**
 * 数据库门面：DB::table('users')->where(...)->get() / DB::select($sql, $bindings)。
 *
 * 门面背后的实例是 {@see DatabaseProxy}：kode/database 的 Db 是静态代理类，
 * 代理以「实例调用 → 转发 Db 静态方法」的方式桥接，避免以实例语法调用静态方法
 * 触发的弃用告警（PHP 8.1+），并为 PHP 9 预留兼容。
 */
final class DB extends Facade
{
    protected static function id(): string
    {
        return DatabaseProxy::class;
    }
}
