<?php

declare(strict_types=1);

namespace Kode\Framework\Tests\Fixtures\Tasks;

use Kode\Framework\Scheduling\Attributes\Cron;
use Kode\Framework\Scheduling\Task;

/**
 * 类级 #[Cron] 测试夹具：handle() 调用会记录到静态数组，便于断言执行。
 */
#[Cron('0 0 * * *', name: 'fixture-record', description: 'fixture class task')]
final class RecordTask extends Task
{
    /** @var list<string> */
    public static array $calls = [];

    public function handle(): void
    {
        self::$calls[] = 'handle:' . $this->note;
    }

    public string $note = 'default';
}
