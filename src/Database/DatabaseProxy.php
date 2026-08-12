<?php

declare(strict_types=1);

namespace Kode\Framework\Database;

use Kode\Database\Db\Db;

/**
 * 数据库门面背后的实例代理
 *
 * kode/database 的 Db 是「静态代理」类：Db::table() / Db::select() / ... 全部是
 * 静态方法。若直接把 Db 当作门面背后的实例，门面会以「实例方式」调用其静态方法，
 * 在 PHP 8.1+ 触发 E_DEPRECATED，并在 PHP 9 直接报错（静态方法不可再以实例语法调用）。
 *
 * 本代理以真正的实例接收门面的实例调用，再显式转发给 Db 的静态方法，从而：
 *  - 消除弃用告警，兼容未来 PHP 版本；
 *  - 完整覆盖 Db 的全部方法（含 Db 自身的 __callStatic 兜底）。
 */
final class DatabaseProxy
{
    /**
     * 把任意实例调用转发到 Db 的同名静态方法。
     */
    public function __call(string $name, array $arguments): mixed
    {
        return Db::$name(...$arguments);
    }
}
