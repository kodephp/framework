<?php

declare(strict_types=1);

namespace Kode\Framework\Database;

use Kode\Database\Model\Model as BaseModel;

/**
 * 模型基类（继承 kode/database 的 ORM 模型）
 *
 * 业务模型继承本类即可获得完整 ORM 能力（与 Laravel/Hyperf/ThinkPHP 用法对齐）：
 *
 *   use Kode\Framework\Database\Model;
 *
 *   final class User extends Model
 *   {
 *       protected string $table = 'users';
 *       protected array $fillable = ['name', 'email'];
 *   }
 *
 *   $user = User::create(['name' => 'Kode']);
 *   $user->name = 'K'; $user->save();
 *   $found = User::find($user->id);
 *   User::where('name', 'K')->paginate(15);
 *
 * 表名默认取「类名复数蛇形」（如 User → users），可用 $table 覆盖；
 * 连接默认走 config/database.php 的 default，可用 $connection 覆盖。
 */
abstract class Model extends BaseModel
{
}
