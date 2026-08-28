# 数据库（kode/database）

```php
// 查询构造器（推荐，自动防注入）
$user = DB::table('users')->where('id', 1)->first();          // 单行
$rows = DB::table('users')->where('age', '>', 18)->get();     // 集合
$page = DB::table('posts')->paginate(15);                     // 分页

DB::table('users')->insert(['name' => 'Kode', 'age' => 20]);
DB::table('users')->where('id', 1)->update(['age' => 21]);
DB::table('users')->where('id', 1)->delete();

// 原生 SQL（参数绑定，绝不拼接）
$rows = db()->select('SELECT * FROM users WHERE id = ?', [1]);

// 事务
DB::transaction(function () {
    DB::table('accounts')->where('id', 1)->decrement('balance', 100);
    DB::table('accounts')->where('id', 2)->increment('balance', 100);
});
```

连接配置见 `config/database.php`。

#### 表结构（Schema 门面）

`Schema` 门面把 kode/database 的 DDL 构建器变成「生成即执行」的便捷入口：

```php
use Kode\Framework\Database\Schema;

// 建表（回调接收 Schema 实例，可链式定义字段/索引/外键）
Schema::create('users', function (Schema $t): void {
    $t->id();
    $t->string('name', 64);
    $t->string('email', 191)->uniqueKey();
    $t->timestamps();
});

Schema::table('users', fn (Schema $t) => $t->string('avatar')->nullable());
Schema::drop('users');

Schema::tableExists('users');          // bool
Schema::columnExists('users', 'email'); // bool
```

> 当前 kode/database 的 DDL/存在性判断为 MySQL 方言（INFORMATION_SCHEMA），生产以 MySQL 为准；
> sqlite 等其它驱动的支持以包版本为准。

#### ORM 模型

继承 `Kode\Framework\Database\Model`（对齐 Laravel/Hyperf/ThinkPHP 用法）：

```php
use Kode\Framework\Database\Model;

final class User extends Model
{
    protected string $table = 'users';
    protected array $fillable = ['name', 'email'];
}

$user = User::create(['name' => 'Kode', 'email' => 'k@kode.dev']);
$user->name = 'K'; $user->save();
$found = User::find($user->id);
User::where('name', 'K')->paginate(15);
```

表名默认取「类名复数蛇形」（User → users），可用 `$table` 覆盖；连接默认走
`config/database.php` 的 `default`，可用 `$connection` 覆盖。

#### 数据库迁移

迁移文件放在 `database/migrations/`，文件名形如 `2024_01_01_000000_create_users_table.php`：

```php
use Kode\Framework\Database\Migration;
use Kode\Framework\Database\Schema;

final class CreateUsersTable extends Migration
{
    public function up(): void
    {
        $this->create('users', function (Schema $t): void {
            $t->id();
            $t->string('name', 64);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        $this->drop('users');
    }
}
```

执行与回滚（需配置可用的 MySQL 连接）：

```bash
php bin/kode migrate            # 执行待运行迁移
php bin/kode migrate:rollback  # 回滚最近一批
php bin/kode migrate:reset      # 回滚全部
```

迁移记录写入 `migrations` 表（首次自动创建），按文件名时间戳排序、支持按批次回滚。

