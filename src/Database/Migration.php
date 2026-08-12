<?php

declare(strict_types=1);

namespace Kode\Framework\Database;

use Kode\Database\Database\Migrations\Migration as BaseMigration;

/**
 * 迁移基类（继承 kode/database 的迁移基类）
 *
 * 在迁移文件里继承本类并实现 up()/down()：
 *
 *   use Kode\Framework\Database\Schema;
 *
 *   final class CreateUsersTable extends Migration
 *   {
 *       public function up(): void
 *       {
 *           $this->create('users', function (Schema $t) {
 *               $t->id();
 *               $t->string('name');
 *               $t->timestamps();
 *           });
 *       }
 *
 *       public function down(): void
 *       {
 *           $this->drop('users');
 *       }
 *   }
 *
 * 运行：php bin/kode migrate / migrate:rollback / migrate:reset
 *
 * 本类重写了 create/table/drop，使其回调接收 {@see Schema} 实例（与门面一致），
 * 并把 DDL 生成即执行，业务侧写法统一。
 */
abstract class Migration extends BaseMigration
{
    protected function create(string $table, callable $callback): void
    {
        Schema::create($table, $callback);
    }

    protected function table(string $table, callable $callback): void
    {
        Schema::table($table, $callback);
    }

    protected function drop(string $table): void
    {
        Schema::drop($table);
    }
}
