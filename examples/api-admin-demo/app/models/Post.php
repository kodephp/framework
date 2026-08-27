<?php

declare(strict_types=1);

namespace app\models;

use Kode\Framework\Database\Model;

class Post extends Model
{
    protected string $table = 'posts';

    protected array $fillable = [
        'title', 'slug', 'excerpt', 'content', 'status',
        'category_id', 'author_id', 'published_at',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
