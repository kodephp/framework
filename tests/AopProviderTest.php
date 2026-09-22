<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Aop\Aop;
use Kode\Aop\Contract\AspectKernelInterface;
use Kode\Aop\Reflection\MetadataReader;
use Kode\Attributes\Reader;
use Kode\Core\Config\Config;
use Kode\Framework\Aop\AspectScanner;
use Kode\Framework\Application;
use Kode\Framework\Providers\AopServiceProvider;
use Kode\Framework\Tests\Fixtures\Aspects\FixtureAspect;
use PHPUnit\Framework\TestCase;

/**
 * AOP（P1）接线验证：
 *  - AspectScanner 能按 PSR-4 发现标注 #[Aspect] 的切面类；
 *  - AopServiceProvider 把内核接进生命周期（可被 resolve / aop() 助手取到）；
 *  - config/aop.php 的 paths 自动发现 app/aop 下真实切面并织入。
 */
final class AopProviderTest extends TestCase
{
    private string $aspectDir;

    protected function setUp(): void
    {
        parent::setUp();
        if (app() === null) {
            Application::make(\Kode\Framework\Tests\TestCase::SKELETON_ROOT);
        }
        $this->aspectDir = __DIR__ . '/Fixtures/Aspects';
        require_once $this->aspectDir . '/FixtureAspect.php';
    }

    public function testAspectScannerFindsAspectClasses(): void
    {
        $found = (new AspectScanner(new Reader()))->scan([
            'Kode\\Framework\\Tests\\Fixtures\\Aspects\\' => $this->aspectDir,
        ]);

        self::assertContains(FixtureAspect::class, $found);
    }

    public function testKernelBootedByProvider(): void
    {
        $kernel = app()->container->get(AspectKernelInterface::class);

        self::assertInstanceOf(AspectKernelInterface::class, $kernel);
        // aop() 助手返回同一内核实例。
        self::assertSame($kernel, aop());
    }

    public function testAutoDiscoveryRegistersAppAspect(): void
    {
        $kernel = app()->container->get(AspectKernelInterface::class);
        $diagnostics = $kernel->diagnostics();

        self::assertTrue($diagnostics['enabled']);
        // app/aop 下的 #[Aspect] 应被自动发现并织入（无需在 bootstrap.php 手动注册）。
        self::assertGreaterThanOrEqual(1, $diagnostics['aspects'], 'app/aop 下的切面应被自动发现');
    }

    /**
     * config/aop.php 的 strict 必须真正落到 Aop::strict()（静态开关，且要在 boot 前落定）。
     *
     * 旧实现调的是内核实例上不存在的 strict() 方法：抛 Error 被 catch 吞掉，
     * 结果既没开关也无从生效；而 `!empty` 的判法又让 strict=false 永远表达不出来。
     */
    public function testStrictFlagIsAppliedFromConfig(): void
    {
        $container = app()->container;
        /** @var Config $config */
        $config = $container->get(Config::class);
        /** @var bool $before */
        $before = MetadataReader::getCacheStats()['strict'];

        try {
            foreach ([false, true] as $want) {
                $config->set('aop.strict', $want);
                (new AopServiceProvider($container))->register();

                self::assertSame($want, MetadataReader::getCacheStats()['strict']);
                // 开关落地的同时内核照常 boot（旧实现靠 catch 兜底，日志里是一条误导告警）
                self::assertTrue(
                    app()->container->get(AspectKernelInterface::class)->diagnostics()['enabled']
                );
            }
        } finally {
            $config->set('aop.strict', $before);
            Aop::strict($before);
        }
    }

    /**
     * 内核启动失败必须只告警、不掐死注册：非法切面类名走 catch 分支后，
     * AspectKernelInterface 仍要能解析出内核。
     *
     * （catch 里的告警本身走 warn() 降级：Bootstrap 注册阶段 App 单例还没建，
     * 直接 logger() 会二次抛异常把整个引导带崩——skeleton 配置里 strict=true
     * 时全套用例都会因此报错，这条路径由套件启动本身覆盖。）
     */
    public function testBootFailureDoesNotBreakRegistration(): void
    {
        $container = app()->container;
        /** @var Config $config */
        $config = $container->get(Config::class);
        /** @var mixed $aspects */
        $aspects = $config->get('aop.aspects');

        try {
            $config->set('aop.aspects', ['Kode\\Framework\\Tests\\Fixtures\\NoSuchAspect']);
            (new AopServiceProvider($container))->register();

            self::assertInstanceOf(
                AspectKernelInterface::class,
                $container->get(AspectKernelInterface::class)
            );
        } finally {
            $config->set('aop.aspects', $aspects);
        }
    }
}
