<?php

declare(strict_types=1);

namespace Kode\Framework\Tests\Fixtures\Aspects;

use Kode\Aop\Attribute\Aspect;
use Kode\Aop\Attribute\Before;
use Kode\Aop\Runtime\JoinPoint;

/**
 * 测试用切面（仅验证 AspectScanner 能发现标注 #[Aspect] 的类）。
 */
#[Aspect]
final class FixtureAspect
{
    #[Before('execution(* Kode\Framework\Tests\Fixtures\Services\Target::run(..))')]
    public function before(JoinPoint $joinPoint): void
    {
        // 占位通知，测试不依赖其执行结果。
    }
}
