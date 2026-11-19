<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Server\HttpServer;
use Kode\Process\Protocol\HttpProtocol;
use PHPUnit\Framework\TestCase;

/**
 * server.max_body 从配置到 kode/process 的接线门禁。
 *
 * 之前应用层把上传上限调到 200MB，但底层仍按 10MB 判定并在头块阶段断连，
 * 业务连 413 都收不到。这里同时钉住归一规则与透传语句，防止任一侧被拆掉。
 */
final class HttpServerMaxBodyTest extends TestCase
{
    public function testFallbackUsesProcessDefault(): void
    {
        foreach ([null, 0, -1, '', 'abc'] as $configured) {
            self::assertSame(HttpProtocol::MAX_LENGTH, HttpServer::resolveMaxBody($configured));
        }
    }

    public function testPositiveConfiguredValueIsKept(): void
    {
        self::assertSame(209715200, HttpServer::resolveMaxBody('209715200'));
        self::assertSame(1024, HttpServer::resolveMaxBody(1024));
    }

    public function testRunPassesMaxBodyToProcess(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/src/Server/HttpServer.php');

        self::assertMatchesRegularExpression("/'maxBody'\s*=>\s*\\\$maxBody,/", $src);
        self::assertStringContainsString("self::resolveMaxBody(\$this->config['max_body'] ?? null)", $src);
    }

    public function testSkeletonDeclaresMaxBody(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/tests/skeleton/config/server.php');

        self::assertMatchesRegularExpression("/'max_body'\s*=>/", $src);
        self::assertStringContainsString("'SERVER_MAX_BODY'", $src);
    }
}
