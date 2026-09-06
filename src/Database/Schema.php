<?php

declare(strict_types=1);

namespace Kode\Framework\Database;

use InvalidArgumentException;
use Kode\Database\Db\Db;
use Kode\Database\Schema\Schema as BaseSchema;

/**
 * Schema 便捷入口（继承 kode/database 的表结构构建器）
 *
 * 继承 {@see BaseSchema} 以获得完整的字段/索引/外键构建能力（id/string/timestamps/
 * primaryKey/uniqueKey/index/foreign ...），并把 create/table/drop 改为
 * 「生成即执行」：业务侧写
 *
 *   use Kode\Framework\Database\Schema;
 *
 *   Schema::create('users', function (Schema $t) {
 *       $t->id();
 *       $t->string('name');
 *       $t->timestamps();
 *   });
 *
 * 返回值为生成的 DDL（与 kode/database 签名保持一致），副作用是已执行建表。
 * 表/字段存在性检查用 {@see self::tableExists()}/{@see self::columnExistsInDb()}（返回布尔）。
 *
 * DDL 方言自动跟随默认连接的驱动（sqlite/mysql/pgsql…），显式传参可覆盖；
 * 连接不可用时回退包默认 MySQL 方言。
 */
class Schema extends BaseSchema
{
    /**
     * 门面实例化用：允许无参构造（静态方法不依赖实例的表名）。
     */
    public function __construct(string $table = '', ?string $driver = null)
    {
        parent::__construct($table, $driver);
    }

    /**
     * 仅生成建表 DDL（不执行），便于预览/迁移文件复用。
     */
    public static function preview(string $table, callable $callback, ?string $driver = null): string
    {
        $schema = new self($table, $driver ?? self::connectionDriver());
        $callback($schema);

        return $schema->toSql();
    }

    /**
     * 创建表并执行（返回生成的 DDL）。
     *
     * @param string|null $driver 目标驱动；缺省时取默认连接的驱动，保证 DDL 方言与
     *                            执行连接一致（此前恒为 MySQL 方言，sqlite 下建表直接报错）。
     */
    public static function create(string $table, callable $callback, string $driver = null): string
    {
        self::assertIdentifier($table, '表名');
        $schema = new self($table, $driver ?? self::connectionDriver());
        $callback($schema);
        $sql = $schema->toSql();
        Db::statement($sql);

        return $sql;
    }

    /**
     * 修改表并执行（返回生成的 DDL）。
     *
     * @param string|null $driver 目标驱动（同 {@see self::create}，缺省跟默认连接）。
     */
    public static function table(string $table, callable $callback, string $driver = null): string
    {
        self::assertIdentifier($table, '表名');
        $schema = new self($table, $driver ?? self::connectionDriver());
        $callback($schema);
        $sql = $schema->toAlterSql();
        Db::statement($sql);

        return $sql;
    }

    /**
     * 删除表并执行（返回生成的 DDL）。
     */
    public static function drop(string $table): string
    {
        self::assertIdentifier($table, '表名');
        $sql = BaseSchema::drop($table);
        Db::statement($sql);

        return $sql;
    }

    /**
     * 默认连接的驱动名（sqlite/mysql/pgsql…），供 DDL 方言对齐执行连接。
     *
     * 连接未配置/不可用时返回 null（回退包全局默认 MySQL，保持旧行为）。
     */
    private static function connectionDriver(): ?string
    {
        try {
            $driver = Db::getDriver();
        } catch (\Throwable) {
            return null;
        }

        return is_string($driver) && $driver !== '' ? $driver : null;
    }

    /**
     * 校验 SQL 标识符（表名/列名）合法性，拒绝任何非法的注入载体。
     *
     * kode/database 的 hasTable()/hasColumn() 直接把标识符字符串插值进
     * INFORMATION_SCHEMA 查询，若传入用户输入可能造成 SQL 注入。这里在框架侧
     * 强制白名单：仅允许常规 SQL 标识符（字母/下划线开头，后接字母数字下划线，
     * 含可选的反引号包裹与库名前缀 `db.table`）。
     */
    private static function assertIdentifier(string $name, string $label): void
    {
        // 反引号必须成对出现：要么纯裸标识符，要么 `name` 整体包裹。
        $ident = '(`[a-zA-Z_][a-zA-Z0-9_]*`|[a-zA-Z_][a-zA-Z0-9_]*)';
        $pattern = '/^' . $ident . '(\.' . $ident . ')?$/';
        if (!preg_match($pattern, $name)) {
            throw new InvalidArgumentException(
                sprintf('非法的%s标识符：%s（仅允许 [a-zA-Z_][a-zA-Z0-9_]*，可选 ` 成对包裹或 db.table 前缀）', $label, $name)
            );
        }
    }

    /**
     * 表是否存在（执行查询，返回布尔）。
     */
    public static function tableExists(string $table): bool
    {
        self::assertIdentifier($table, '表名');

        return Db::select(BaseSchema::hasTable($table)) !== [];
    }

    /**
     * 字段是否存在（执行查询，返回布尔）。
     */
    public static function columnExistsInDb(string $table, string $column): bool
    {
        self::assertIdentifier($table, '表名');
        self::assertIdentifier($column, '列名');

        return Db::select(BaseSchema::hasColumn($table, $column)) !== [];
    }
}
