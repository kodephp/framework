<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Database\Db\Db;
use Kode\Database\Database\Migrations\Migrator;
use Kode\Database\Event\EventManager;
use Kode\Database\Event\SqlEvent;
use Kode\Framework\Database\Schema;
use Kode\Framework\Database\SlowQueryLogger;
use Kode\Framework\Providers\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * 数据库服务提供者（kode/database）
 *
 * kode/database 是「静态代理」式适配器（Db::table()/select()/...），
 * 启动期按连接名逐一 addConnection()、把 default 设为默认连接，连接懒加载到首次查询。
 * 同时把 Db::class 绑入容器，供 DB 门面 / db() 助手以实例方式代理静态调用；
 * 并注册 Schema 门面与迁移运行器（Migrator）。
 * database.slow_log.enabled 打开时另挂 {@see SlowQueryLogger} 到 kode/database 的事件总线。
 */
final class DatabaseServiceProvider extends ServiceProvider
{
    /** @var bool 慢查询监听是否已挂上（EventManager 是进程级单例） */
    private static bool $slowQueryListenerRegistered = false;

    public function register(): void
    {
        /** @var array<string, mixed> $config */
        $config = (array) $this->config('database', []);

        // kode/database 的 Db 以「连接名」为键管理连接（addConnection），
        // 而非整个嵌套 config。逐条注册 connections，并把 default 设为默认连接，
        // 否则 Db::select() 等默认调用会回退到整个嵌套数组（无顶层 host/密码）导致连库失败。
        $default = (string) ($config['default'] ?? 'mysql');
        foreach (($config['connections'] ?? []) as $name => $connection) {
            /** @var array<string, mixed> $connection */
            Db::addConnection((string) $name, $connection);
        }
        Db::setDefaultConnection($default);
        Db::setConfig($config['connections'][$default] ?? []);

        $this->container->singleton(Db::class, static fn(): Db => new Db());
        $this->container->alias('db', Db::class);

        // Schema 门面（生成即执行的 DDL 便捷入口）。
        $this->container->singleton(Schema::class, static fn(): Schema => new Schema());
        $this->container->alias('schema', Schema::class);

        // 迁移运行器：扫描 database/migrations 目录，按文件名时间戳排序执行。
        $this->container->singleton(Migrator::class, fn(): Migrator => new Migrator(
            $this->basePath('database/migrations')
        ));

        $this->registerSlowQueryLog($config);
    }

    /**
     * 慢查询 / 失败查询日志：把 {@see SlowQueryLogger} 挂进 kode/database 的事件总线。
     *
     * 只挂一次：EventManager 是进程级单例，同进程二次引导（测试、热重载）会重复注册，
     * 一条慢查询就被记两遍。
     *
     * @param array<string, mixed> $config database 配置
     */
    private function registerSlowQueryLog(array $config): void
    {
        $slowLog = (array) ($config['slow_log'] ?? []);

        if (!filter_var($slowLog['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        if (self::$slowQueryListenerRegistered) {
            return;
        }
        self::$slowQueryListenerRegistered = true;

        $container = $this->container;
        EventManager::getInstance()->listen(SqlEvent::class, new SlowQueryLogger(
            static fn (): ?LoggerInterface => $container->has(LoggerInterface::class)
                ? $container->get(LoggerInterface::class)
                : null,
            (float) ($slowLog['threshold'] ?? SlowQueryLogger::DEFAULT_THRESHOLD)
        ));
    }
}
