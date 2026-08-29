<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Aop\Contract\AspectKernelInterface;
use Kode\Attributes\Reader;
use Kode\Framework\Aop\AspectScanner;
use Kode\Framework\Application;
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
}
