<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Application;

/**
 * 框架仓库自身集成测试的基类。
 *
 * 背景：v1.0.0 起 framework 仓库收敛为「纯内核」——仓库根不再携带 app/ config/
 * lang/ database/ 骨架（骨架独立为 kode/skeleton 仓库，经 export-ignore 也不进入
 * vendor/kode/framework 分发包）。而集成测试必须引导一个真实应用，因此把一份
 * 骨架夹具固化在 tests/skeleton/，本基类将默认引导根指向它。
 *
 * 约定：
 *  - 对外的 {@see \Kode\Framework\Testing\TestCase} 默认仍以 getcwd() 为项目根
 *    （消费者项目自带 config/），本类不改动其对外语义，仅在本仓库内覆写默认值；
 *  - 需要显式换引导根的用例仍可传参 bootApp($path)，参数优先。
 */
abstract class TestCase extends \Kode\Framework\Testing\TestCase
{
    /**
     * 骨架夹具根目录。
     *
     * 直接调用 Application::make() 的用例（不经过 bootApp()）同样应以此为根，
     * 请用 \Kode\Framework\Tests\TestCase::SKELETON_ROOT 完全限定名引用，
     * 避免与 PHPUnit\Framework\TestCase 的导入别名冲突。
     */
    public const SKELETON_ROOT = __DIR__ . '/skeleton';

    /**
     * 默认引导根：仓库内的骨架夹具（含 app/ config/ lang/ database/）。
     *
     * 取代父类默认的 getcwd()——框架根已无骨架，直接引导会缺配置。
     */
    protected string $basePath = self::SKELETON_ROOT;

    protected function bootApp(string $basePath = ''): Application
    {
        return parent::bootApp($basePath !== '' ? $basePath : $this->basePath);
    }
}
