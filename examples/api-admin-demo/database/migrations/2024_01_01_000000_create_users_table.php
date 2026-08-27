<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Framework\Database\Schema;

final class CreateUsersTable extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Schema $t): void {
            $t->id();
            $t->string('username', 64);
            $t->uniqueKey('username');
            $t->string('email', 128)->nullable();
            $t->string('password', 255);
            $t->string('display_name', 64)->default('');
            $t->string('role', 32)->default('user');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop('users');
    }
}
