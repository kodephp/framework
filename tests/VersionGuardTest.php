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

    /**
     * README 抄了四处版本号（「当前 `X`」、「当前版本：**[vX」、横幅样例的 `Kode Framework version:X`、
     * `/health` 样例里的 `"version":"X"`），漏改一处就是文档在指一次不存在的发布。
     * 历史条目（如「v1.8.1 修正」）不在自述行这些措辞里。
     */
    public function testReadmeVersionClaimsMatchTheConstant(): void
    {
        $readme = (string) file_get_contents(\dirname(__DIR__) . '/README.md');
        $hits = [];
        preg_match_all(
            '/当前 `([0-9]+\.[0-9]+\.[0-9]+)`|当前版本：\*\*\[v([0-9]+\.[0-9]+\.[0-9]+)'
            . '|Kode Framework version:([0-9]+\.[0-9]+\.[0-9]+)|"version":"([0-9]+\.[0-9]+\.[0-9]+)"/u',
            $readme,
            $hits,
            PREG_SET_ORDER
        );

        self::assertNotEmpty($hits, 'README 里的版本自述行找不到了：措辞变了就同步改这条守卫');

        foreach ($hits as $hit) {
            $stated = ($hit[1] ?? '') !== '' ? $hit[1]
                : (($hit[2] ?? '') !== '' ? $hit[2]
                : (($hit[3] ?? '') !== '' ? $hit[3] : ($hit[4] ?? '')));
            self::assertSame(Application::VERSION, $stated,
                "README 自述的版本 {$stated} 与 Application::VERSION 不一致");
        }
    }
}
