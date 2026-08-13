<?php

declare(strict_types=1);

namespace Kode\Framework\Feature\Attributes;

use Attribute;

/**
 * 路由级功能开关声明。
 *
 * 用在控制器类（对所有方法生效）或方法（覆盖类级）上：
 *
 *   #[Feature('new-checkout')]
 *   public function checkout() {}
 *
 *   #[Feature('beta-search', fallback: 403)]
 *   public function search() {}
 *
 * 关闭时由 FeatureMiddleware 返回 fallback（默认 404），不影响其它路由。
 * 业务代码内也可用 feature('new-checkout') 即时判定（不依赖路由属性）。
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Feature
{
    public function __construct(
        public string $name,
        public int $fallback = 404,
    ) {
    }
}
