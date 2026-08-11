<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Translation\Translator;
use PHPUnit\Framework\TestCase;

/**
 * 国际化单元测试：直接构造 Translator（复用 symfony/translation），
 * 验证多语种加载、占位符替换与缺失 key 兜底。
 */
final class TranslatorTest extends TestCase
{
    private function makeTranslator(): Translator
    {
        return new Translator(
            ['default' => 'zh-CN'],
            [__DIR__ . '/../lang'],
        );
    }

    public function testTranslatesWithPlaceholders(): void
    {
        $t = $this->makeTranslator();

        self::assertSame('欢迎，Kode', $t->trans('welcome', ['name' => 'Kode']));
        self::assertSame('用户 42 不存在', $t->trans('user_not_found', ['id' => 42]));
    }

    public function testSwitchesLocale(): void
    {
        $t = $this->makeTranslator();
        self::assertSame('zh-CN', $t->getLocale());

        $t->setLocale('en');
        self::assertSame('en', $t->getLocale());
        self::assertSame('Welcome, Kode', $t->trans('welcome', ['name' => 'Kode']));
    }

    public function testMissingKeyReturnsId(): void
    {
        $t = $this->makeTranslator();

        self::assertSame('app.unknown_key', $t->trans('app.unknown_key'));
    }
}
