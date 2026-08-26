<?php

declare(strict_types=1);

namespace Kode\Framework\Tests\Fixtures\Controllers;

use Kode\Framework\Http\Attributes\Controller;
use Kode\Framework\Http\Attributes\Get;

/**
 * 方法可见性路由夹具：验证仅 public 方法可注册为路由。
 *
 * - publicRoute()      → 注册（预期）
 * - privateRoute()     → 跳过（非 public 不可作为路由处理器）
 * - protectedRoute()   → 跳过（同上）
 */
#[Controller(prefix: '/visibility')]
final class VisibilityController
{
    #[Get('/public')]
    public function publicRoute(): void
    {
    }

    #[Get('/secret')]
    private function privateRoute(): void
    {
    }

    #[Get('/internal')]
    protected function protectedRoute(): void
    {
    }
}