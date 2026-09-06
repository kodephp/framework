<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Database\Database\Migrations\Migrator;
use Kode\Framework\Application;
use Kode\Framework\Database\Model;
use Kode\Framework\Database\Schema as FrameworkSchema;
use Kode\Framework\Facades\Schema;
use PHPUnit\Framework\TestCase;

/**
 * 数据库增强（kode/database 1.15）离线冒烟测试。
 *
 * 验证框架薄封装（Schema 门面 / Model 基类 / Migrator 接线）逻辑正确，
 * 不建立真实数据库连接。Schema DDL 与迁移执行需在 MySQL 环境运行（包内
 * tableExists 为 MySQL 方言），真实端到端迁移由本地 MySQL 可行性验证覆盖。
 */
final class DatabaseFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Application::make(\Kode\Framework\Tests\TestCase::SKELETON_ROOT);
    }

    public function testSchemaFacadeResolvesToWrapper(): void
    {
        self::assertInstanceOf(FrameworkSchema::class, Schema::getInstance());
        self::assertInstanceOf(FrameworkSchema::class, schema());
    }

    public function testSchemaToSqlBuildsDdl(): void
    {
        // kode/database 1.15.1 已修复 Column.php 的 primary_key 未初始化 warning，
        // 此处不再需要 @ 抑制，直接校验框架薄封装产出的 DDL 正确。
        $sql = FrameworkSchema::preview('users', function (FrameworkSchema $t): void {
            $t->id();
            $t->string('name', 64);
            $t->timestamps();
        });

        self::assertStringContainsString('CREATE TABLE', $sql);
        self::assertStringContainsString('users', $sql);
        self::assertStringContainsString('name', $sql);
        // 框架层 create()/table()/drop()/tableExists()/columnExists() 均为公开静态方法。
        self::assertTrue(method_exists(FrameworkSchema::class, 'create'));
        self::assertTrue(method_exists(FrameworkSchema::class, 'drop'));
        self::assertTrue(method_exists(FrameworkSchema::class, 'tableExists'));
        self::assertTrue(method_exists(FrameworkSchema::class, 'columnExistsInDb'));
    }

    public function testSchemaPreviewFollowsSqliteDialect(): void
    {
        // 显式 sqlite 方言：自增列须为 SQLite 合法形态（无 UNSIGNED/ENGINE）.
        $sql = FrameworkSchema::preview('roles', function (FrameworkSchema $t): void {
            $t->id();
            $t->string('name', 64);
        }, 'sqlite');

        self::assertStringContainsString('AUTOINCREMENT', $sql);
        self::assertStringNotContainsString('UNSIGNED', $sql);
        self::assertStringNotContainsString('ENGINE=', $sql);
    }

    public function testModelBaseInfersTableAndFills(): void
    {
        $user = new class extends Model
        {
            protected string $table = 'users';
            protected array $fillable = ['name', 'email'];
        };

        $user->fill(['name' => 'Kode', 'email' => 'k@kode.dev', 'secret' => 'x']);

        self::assertSame('users', $user->getTable());
        self::assertSame('Kode', $user->getAttribute('name'));
        // 非 fillable 字段不应被批量赋值（防批量赋值漏洞）。
        self::assertArrayNotHasKey('secret', $user->getAttributes());
    }

    public function testMigratorDiscoversFilesWithoutDb(): void
    {
        // 框架自带示例迁移应被发现（文件名含 create_users_table）。
        // 注意：getPendingFiles()/run() 会真实连库（MySQL），离线测试只验证文件发现。
        $real = new Migrator(\Kode\Framework\Tests\TestCase::SKELETON_ROOT . '/database/migrations');
        $files = $real->getMigrationFiles();
        self::assertNotEmpty($files);
        self::assertStringContainsString('create_users_table', (string) $files[0]);
    }

    /**
     * 安全：表名/列名标识符白名单校验，拒绝 SQL 注入载体。
     *
     * kode/database 的 hasTable()/hasColumn()/DDL 直接插值标识符，框架侧
     * 必须强制白名单。此处用反射测断言方法本身（不连库）。
     */
    public function testIdentifierValidationRejectsInjection(): void
    {
        $method = new \ReflectionMethod(FrameworkSchema::class, 'assertIdentifier');
        $method->setAccessible(true);

        // 合法标识符（含 db.table 前缀与反引号包裹）应通过。
        $valid = ['users', 'my_table', 'Users2', '`users`', 'app_db.users', '`app_db`.`users`'];
        foreach ($valid as $name) {
            $method->invoke(null, $name, '表名');
        }
        self::assertTrue(true); // 合法集合未抛异常

        // 非法标识符（注入载体）必须抛 InvalidArgumentException。
        $invalid = [
            'users; DROP TABLE users',
            "users' OR '1'='1",
            'users`',
            '1users',          // 数字开头非法
            'users.name.col',  // 多级点号非法
            '',
        ];
        foreach ($invalid as $name) {
            try {
                $method->invoke(null, $name, '表名');
                self::fail(sprintf('期望 InvalidArgumentException：%s', $name));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('非法的表名标识符', $e->getMessage());
            }
        }
    }

    public function testSchemaMethodsValidateIdentifierBeforeDb(): void
    {
        // 直接调用静态方法，断言在连库之前即抛出校验异常（安全前置）。
        $this->expectException(\InvalidArgumentException::class);
        FrameworkSchema::tableExists("users'; DROP TABLE users; --");
    }

    /**
     * 回归：kode/database 1.15.0 三个异常类的 make() 与父类签名不兼容，类被加载即 fatal。
     * 1.15.1 父类移除 make()（改 sql()），子类 make() 不再覆盖父类，加载无致命。
     * 此处显式加载并调用 make() 确认该回归已修复。
     */
    public function testDatabaseExceptionClassesLoadWithoutFatal(): void
    {
        $classes = [
            \Kode\Database\Exception\ConnectionException::class,
            \Kode\Database\Exception\QueryException::class,
            \Kode\Database\Exception\ModelNotFoundException::class,
        ];
        foreach ($classes as $class) {
            self::assertTrue(class_exists($class), "{$class} 应可加载");
        }

        // 调用 make() 工厂不应触发致命/异常（仅构造一个异常实例）。
        $connEx = \Kode\Database\Exception\ConnectionException::make('default');
        self::assertInstanceOf(\Kode\Database\Exception\ConnectionException::class, $connEx);

        $queryEx = \Kode\Database\Exception\QueryException::make('SELECT 1', []);
        self::assertInstanceOf(\Kode\Database\Exception\QueryException::class, $queryEx);

        $modelEx = \Kode\Database\Exception\ModelNotFoundException::make(\Kode\Framework\Database\Model::class);
        self::assertInstanceOf(\Kode\Database\Exception\ModelNotFoundException::class, $modelEx);
    }
}
