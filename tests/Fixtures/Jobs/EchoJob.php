<?php

declare(strict_types=1);

namespace Kode\Framework\Tests\Fixtures\Jobs;

use Kode\Queue\Attribute\AsJob;

/**
 * 队列消费测试夹具：标注 #[AsJob]，handle() 记录被调用痕迹，供测试断言。
 */
#[AsJob('echo.job')]
final class EchoJob
{
    public static bool $handled = false;

    /** @var array<string, mixed> */
    public static array $payload = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        self::$payload = $payload;
        self::$handled = true;
    }
}
