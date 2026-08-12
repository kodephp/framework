<?php

declare(strict_types=1);

namespace Kode\Framework\Database;

use Kode\Database\Db\Db;

/**
 * 数据填充基类
 *
 * 在 database/seeders/ 下继承本类实现 run()，用 db() 便捷访问数据库：
 *
 *   use Kode\Framework\Database\Seeder;
 *
 *   final class DatabaseSeeder extends Seeder
 *   {
 *       public function run(): void
 *       {
 *           $this->call(UsersTableSeeder::class);
 *           $this->call(PostsTableSeeder::class);
 *       }
 *   }
 *
 * 运行：php bin/kode db:seed（默认跑 DatabaseSeeder），或 db:seed --class=UsersTableSeeder。
 *
 * 说明：seeder 文件默认无命名空间（全局类），db:seed 会一次性 require 整个
 * seeders 目录，因此 call(OtherSeeder::class) 可正常解析；若你给 seeder 加了命名空间，
 * 请确保该命名空间已在 composer 自动加载中。
 */
abstract class Seeder
{
    /**
     * 填充逻辑（子类实现）。
     */
    abstract public function run(): void;

    /**
     * 链式调用其它 seeder（常用于 DatabaseSeeder 聚合多个子 seeder）。
     *
     * @template T of Seeder
     * @param class-string<T> $seeder
     * @return T
     */
    protected function call(string $seeder): Seeder
    {
        if (!class_exists($seeder)) {
            throw new \RuntimeException("Seeder 类不存在：{$seeder}（请确认 database/seeders 下已定义）");
        }

        /** @var Seeder $instance */
        $instance = new $seeder();
        $instance->run();

        return $instance;
    }

    /**
     * 数据库便捷入口（kode/database 的 Db 单例）。
     */
    protected function db(): Db
    {
        /** @var Db $db */
        $db = resolve(Db::class);

        return $db;
    }
}
