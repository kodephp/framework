<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Context\Context;
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

    /**
     * 语种应优先取自请求上下文（kode/context），而非共享实例默认。
     * 这保证了并发（fiber/Swoole）下不同请求不会互相改写语种。
     */
    public function testLocaleReadsFromContextWhenSet(): void
    {
        $t = $this->makeTranslator();
        self::assertSame('zh-CN', $t->getLocale());

        Context::set('locale', 'en');
        try {
            self::assertSame('en', $t->getLocale());
            self::assertSame('Welcome, Kode', $t->trans('welcome', ['name' => 'Kode']));
        } finally {
            Context::delete('locale');
        }

        // 清除上下文后应回落回实例默认语种。
        self::assertSame('zh-CN', $t->getLocale());
    }

    /**
     * 语种应取自请求上下文（kode/context 作用域），作用域结束后自动回滚，
     * 不会污染外层/其它请求。并发（fiber）隔离由 kode/context 的 currentScope()
     * 按执行单元（Fiber/协程）分桶保证，此处用 fork 作用域验证同一机制。
     */
    public function testLocaleIsIsolatedByContextScope(): void
    {
        $t = $this->makeTranslator();

        $en = Context::fork(static function () use ($t): string {
            Context::set('locale', 'en');
            return $t->trans('welcome', ['name' => 'Kode']);
        });
        $zh = Context::fork(static function () use ($t): string {
            Context::set('locale', 'zh-CN');
            return $t->trans('welcome', ['name' => 'Kode']);
        });

        self::assertSame('Welcome, Kode', $en);
        self::assertSame('欢迎，Kode', $zh);

        // 作用域回滚后，外层/其它请求不受影响。
        self::assertFalse(Context::has('locale'));
        self::assertSame('zh-CN', $t->getLocale());
    }
}
