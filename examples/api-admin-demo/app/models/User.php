<?php

declare(strict_types=1);

namespace app\models;

use Kode\Framework\Database\Model;

class User extends Model
{
    protected string $table = 'users';

    protected array $fillable = [
        'username', 'email', 'password', 'display_name', 'role',
    ];

    protected array $hidden = [
        'password',
    ];
}
