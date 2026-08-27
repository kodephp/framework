<?php

declare(strict_types=1);

namespace app\models;

use Kode\Framework\Database\Model;

class Category extends Model
{
    protected string $table = 'categories';

    protected array $fillable = [
        'name', 'slug',
    ];
}
