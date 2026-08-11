<?php

declare(strict_types=1);

namespace Kode\Framework\Tests\Fixtures\Tasks;

use Kode\Framework\Scheduling\Attributes\Cron;

/**
 * 方法级 #[Cron] 测试夹具：一个类挂多条任务，其中一条禁用。
 */
final class MultiTask
{
    /** @var list<string> */
    public static array $calls = [];

    #[Cron('* * * * *', name: 'fixture-a', description: 'fixture method a')]
    public function a(): void
    {
        self::$calls[] = 'a';
    }

    #[Cron('0 0 * * *', name: 'fixture-b', enabled: false, description: 'fixture method b (disabled)')]
    public function b(): void
    {
        self::$calls[] = 'b';
    }
}
