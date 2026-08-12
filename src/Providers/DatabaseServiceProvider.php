<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Database\Db\Db;
use Kode\Database\Database\Migrations\Migrator;
use Kode\Framework\Database\Schema;
use Kode\Framework\Providers\ServiceProvider;

/**
 * 数据库服务提供者（kode/database）
 *
 * kode/database 是「静态代理」式适配器（Db::table()/select()/...），
 * 启动期把 config/database.php 喂给 Db::setConfig() 即可，连接懒加载到首次查询。
 * 同时把 Db::class 绑入容器，供 DB 门面 / db() 助手以实例方式代理静态调用；
 * 并注册 Schema 门面与迁移运行器（Migrator）。
 */
final class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /** @var array<string, mixed> $config */
        $config = (array) $this->config('database', []);
        Db::setConfig($config);

        $this->container->singleton(Db::class, static fn(): Db => new Db());
        $this->container->alias('db', Db::class);

        // Schema 门面（生成即执行的 DDL 便捷入口）。
        $this->container->singleton(Schema::class, static fn(): Schema => new Schema());
        $this->container->alias('schema', Schema::class);

        // 迁移运行器：扫描 database/migrations 目录，按文件名时间戳排序执行。
        $this->container->singleton(Migrator::class, fn(): Migrator => new Migrator(
            $this->basePath('database/migrations')
        ));
    }
}
