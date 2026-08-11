<?php

declare(strict_types=1);

namespace Kode\Framework\Facades;

use Kode\Core\Facade;
use Kode\Database\Db\Db;

/**
 * 数据库门面：DB::table('users')->where(...)->get() / DB::select($sql, $bindings)。
 *
 * kode/database 的 Db 是静态代理类，门面以实例方式代理其静态方法。
 */
final class DB extends Facade
{
    protected static function id(): string
    {
        return Db::class;
    }
}
