<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Server\HttpServer;
use PHPUnit\Framework\TestCase;

/**
 * server.name 与「残留 worker 收尸」的口径一致性。
 *
 * 进程标题由 kode/process 写成 "{name}: {role}"，CLI 停服时按同名前缀清理孤儿 worker。
 * 匹配串曾把 'kode-http:' 写死在 kode 脚本里：自定义 SERVER_NAME 的项目收尸永远命中 0 个，
 * 孤儿 worker 占着端口让 restart 走到「端口仍被占用」而中止。
 */
final class ServerNameTest extends TestCase
{
    public function testNameFallsBackToDefaultWhenBlank(): void
    {
        foreach ([null, '', '   ', "\t"] as $configured) {
            self::assertSame(HttpServer::DEFAULT_NAME, HttpServer::resolveName($configured));
        }
    }

    public function testNameIsTrimmedNotRewritten(): void
    {
        self::assertSame('my-admin', HttpServer::resolveName(' my-admin '));
        self::assertSame('my-admin:', HttpServer::processMatchToken(' my-admin '));
    }

    /** 标题匹配串必须是「名字 + 冒号」：与 kode/process 的 "%s: %s" 标题格式对齐。 */
    public function testMatchTokenIsNameWithColon(): void
    {
        self::assertSame('kode-http:', HttpServer::processMatchToken(null));
        self::assertSame('kode-http:', HttpServer::processMatchToken(HttpServer::DEFAULT_NAME));
        self::assertSame('my-admin:', HttpServer::processMatchToken('my-admin'));
    }

    /** CLI 脚本不得再把默认名写死在匹配处（两处 ps 扫描都要吃配置）。 */
    public function testCliReaperDerivesTokenFromConfig(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/kode');

        self::assertStringNotContainsString("str_contains(\$line, 'kode-http:')", $src);
        self::assertStringContainsString('HttpServer::processMatchToken($config[\'name\'] ?? null)', $src);
    }
}
