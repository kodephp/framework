<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Server\HttpServer;
use PHPUnit\Framework\TestCase;

/**
 * 运行时路径解析（多实例按端口隔离，v1.3.0）。
 *
 * 规则锁定：
 *  - 默认按端口分片（storage/runtime/9527），不同端口实例互不覆盖；
 *  - 未配端口回退旧目录（storage/runtime），老版本行为不变；
 *  - 显式 runtime_path / pid_file 保持最高优先级。
 */
final class HttpServerPathsTest extends TestCase
{
    public function testDefaultPathsAreNamespacedByPort(): void
    {
        $paths = HttpServer::resolveRuntimePaths('/srv/app', ['port' => 9599]);

        self::assertSame('/srv/app/storage/runtime/9599', $paths['runtime_dir']);
        self::assertSame('/srv/app/storage/runtime/9599/kode.pid', $paths['pid_file']);
        self::assertSame('/srv/app/storage/runtime/9599/kode.log', $paths['log_file']);
    }

    public function testMissingPortFallsBackToLegacyDir(): void
    {
        $paths = HttpServer::resolveRuntimePaths('/srv/app', []);

        self::assertSame('/srv/app/storage/runtime', $paths['runtime_dir']);
        self::assertSame('/srv/app/storage/runtime/kode.pid', $paths['pid_file']);
    }

    public function testExplicitRuntimePathWins(): void
    {
        $paths = HttpServer::resolveRuntimePaths('/srv/app', ['port' => 9599, 'runtime_path' => 'var/run/kode']);

        self::assertSame('/srv/app/var/run/kode', $paths['runtime_dir']);
        self::assertSame('/srv/app/var/run/kode/kode.pid', $paths['pid_file']);
    }

    public function testExplicitPidFileWins(): void
    {
        $paths = HttpServer::resolveRuntimePaths('/srv/app', ['port' => 9599, 'pid_file' => '/tmp/my.pid']);

        self::assertSame('/tmp/my.pid', $paths['pid_file']);
    }

    public function testStringPortIsNormalized(): void
    {
        // CLI --port 以字符串透传，解析层必须归一化（'9599' 与 9599 同路径）。
        $a = HttpServer::resolveRuntimePaths('/srv/app', ['port' => '9599']);
        $b = HttpServer::resolveRuntimePaths('/srv/app', ['port' => 9599]);

        self::assertSame($a, $b);
    }
}
