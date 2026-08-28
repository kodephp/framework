<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Framework\Database\Schema;

final class CreateCategoriesTable extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Schema $t): void {
            $t->id();
            $t->string('name', 64);
            $t->string('slug', 64);
            $t->uniqueKey('slug');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop('categories');
    }
}
