<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Testing\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * 配置中心未启用时：ConfigCenterServiceProvider 不接线，config_center() 返回 null（零副作用）。
 *
 * 用独立进程隔离全局 app() 单例，避免被其他测试类已引导的启用态污染。
 */
final class ConfigCenterDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        putenv('CONFIG_CENTER_ENABLED=false');
        putenv('CONFIG_CENTER_FILE');

        $this->bootApp(getcwd());
    }

    protected function tearDown(): void
    {
        putenv('CONFIG_CENTER_ENABLED');
        putenv('CONFIG_CENTER_FILE');
        parent::tearDown();
    }

    #[RunInSeparateProcess]
    public function testHelperReturnsNullWhenDisabled(): void
    {
        self::assertNull(config_center());
    }
}
