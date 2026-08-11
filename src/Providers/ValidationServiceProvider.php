<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Providers\ServiceProvider;
use Kode\Framework\Validation\Validator;

/**
 * 验证服务提供者（基于 Symfony Validator）
 *
 * 框架的验证能力复用成熟的 Symfony Validator，而非重复造轮子；
 * 通过 Validator 封装为简洁的规则串语法，并在需要时支持属性约束。
 */
final class ValidationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Validator::class, fn(): Validator => new Validator());
        $this->container->alias('validator', Validator::class);
    }
}
