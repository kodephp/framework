<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Translation\Translator;
use PHPUnit\Framework\TestCase;

/**
 * 国际化「多模块/域」隔离测试。
 *
 * 验证不同业务模块（order / user ...）的文案通过独立域文件
 * lang/<locale>/<module>.php 加载，互不污染；并支持 'module::key' 简写。
 */
final class I18nModuleTest extends TestCase
{
    private function translator(): Translator
    {
        return new Translator(['default' => 'zh-CN'], [__DIR__ . '/Fixtures/lang']);
    }

    public function testModuleDomainLoadedSeparately(): void
    {
        $t = $this->translator();

        // 默认域（messages）与 order 域互不影响。
        self::assertSame('订单 1001 已创建', $t->trans('order::created', ['id' => 1001]));
        self::assertSame('Order 1001 created', $t->trans('order::created', ['id' => 1001], null, 'en'));
    }

    public function testTransModuleHelper(): void
    {
        $t = $this->translator();

        self::assertSame('订单 7 已支付', $t->transModule('order', 'paid', ['id' => 7]));
    }

    public function testModuleKeyDoesNotLeakIntoMessagesDomain(): void
    {
        $t = $this->translator();

        // 在 messages 域里取 order 模块的 key，应回退为 key 本身（未定义）。
        self::assertSame('created', $t->trans('created'));
        // 但在 order 域里应能取到。
        self::assertSame('订单 1 已创建', $t->trans('created', ['id' => 1], 'order', 'zh-CN'));
    }

    public function testHasDomain(): void
    {
        $t = $this->translator();
        $t->trans('order::created');

        self::assertTrue($t->hasDomain('zh-CN', 'order'));
        self::assertFalse($t->hasDomain('zh-CN', 'nonexistent'));
    }
}
