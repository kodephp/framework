<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Framework\Database\Schema;

final class CreatePostsTable extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Schema $t): void {
            $t->id();
            $t->string('title', 255);
            $t->string('slug', 255);
            $t->string('excerpt', 512)->nullable();
            $t->text('content');
            $t->string('status', 32)->default('draft'); // draft | published
            $t->integer('category_id')->nullable();
            $t->integer('author_id')->nullable();
            $t->string('published_at', 32)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop('posts');
    }
}
