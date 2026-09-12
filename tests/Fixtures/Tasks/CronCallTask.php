<?php

declare(strict_types=1);

namespace Kode\Framework\Tests\Fixtures\Tasks;

/**
 * 测试用调用记录任务：供 PluginManager::addCron() 以「类方法」形式指向。
 *
 * 注意：本类**刻意不标注 #[Cron]**——它只作为 addCron() 的调用目标，
 * 不能被 TaskScanner 扫成自动任务（否则会与用例手工注册的任务重复执行）。
 * 记录内容只有方法名：调度器按容器解析实例，构造函数默认值无法区分是哪条注册触发。
 */
final class CronCallTask
{
    /** @var list<string> */
    public static array $calls = [];

    public function handle(): void
    {
        self::$calls[] = 'handle';
    }

    public function a(): void
    {
        self::$calls[] = 'a';
    }

    public function b(): void
    {
        self::$calls[] = 'b';
    }
}
