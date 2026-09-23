<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Testing\TestCase as BaseTestCase;

/**
 * `Testing\TestCase` 自身的引导隔离回归（v1.10.1）。
 *
 * 修的是「independentApp 只读不用」：旧实现先无条件把 kode/core 单例清掉，
 * 再看 `self::$app` 缓存——命中就把那个**地基已被抽走**的 Application 交回去。
 * 缓存平时由基类 tearDown 清空，所以只有「前一个测试类盖掉 tearDown 没回父类」
 * 这种顺序才会踩中，表现为后一个类 resolve() 抛「服务容器尚未启动」，
 * 单跑那个类永远绿（消费方项目真实踩过，见 kode_test/tests/PluginSupplyChainTest）。
 */
final class TestingTestCaseIsolationTest extends BaseTestCase
{
    protected string $basePath = \Kode\Framework\Tests\TestCase::SKELETON_ROOT;

    public function test_default_boot_reuses_the_cached_instance(): void
    {
        $first = $this->bootApp(\Kode\Framework\Tests\TestCase::SKELETON_ROOT);
        self::assertSame($first, $this->bootApp(), '默认（independentApp=false）必须复用首个实例');
    }

    public function test_independent_boot_rebuilds_instead_of_returning_the_stale_cache(): void
    {
        $shared = $this->bootApp(\Kode\Framework\Tests\TestCase::SKELETON_ROOT);

        // 等价于「上一个类忘了清缓存，下一个类要求独立实例」这种跨类顺序
        $this->independentApp = true;
        $rebuilt = $this->bootApp(\Kode\Framework\Tests\TestCase::SKELETON_ROOT);

        self::assertNotSame($shared, $rebuilt, 'independentApp 必须真的重建实例，否则开关只是读了一下');
        self::assertNotSame($shared, $this->bootApp(), '重建后的缓存也必须换成新实例');
    }

    public function test_rebuilt_instance_is_usable_and_the_request_path_resolves(): void
    {
        $this->bootApp(\Kode\Framework\Tests\TestCase::SKELETON_ROOT);
        $this->independentApp = true;
        $this->bootApp(\Kode\Framework\Tests\TestCase::SKELETON_ROOT);

        // 旧实现在这里交回陈应用：控制器解析直接抛「服务容器尚未启动」
        $this->get('/')->assertStatus(200);
        self::assertNotNull(\Kode\Core\App::getInstance(), '核心单例必须随新实例一起引导');
    }
}
