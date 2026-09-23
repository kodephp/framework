<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Application;
use PHPUnit\Framework\TestCase;

/**
 * 版本常量防漂移：Application::VERSION 会出现在 /health 与日志里，
 * 与 composer.json 的 version 不一致就会让人按错误版本排查问题。
 */
final class VersionGuardTest extends TestCase
{
    public function testVersionConstantMatchesComposerManifest(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(\dirname(__DIR__) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertIsArray($manifest);
        self::assertSame(
            $manifest['version'] ?? null,
            Application::VERSION,
            'src/Application.php 的 VERSION 与 composer.json 的 version 已漂移，发版时两处要一起改'
        );
        self::assertSame(Application::VERSION, Application::version(), 'version() 必须回读同一常量');
    }
}
