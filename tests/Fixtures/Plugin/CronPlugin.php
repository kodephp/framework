<?php

declare(strict_types=1);

namespace Kode\Framework\Tests\Fixtures\Plugin;

use Kode\Framework\Plugin\PluginInterface;
use Kode\Framework\Plugin\PluginManager;
use Kode\Framework\Tests\Fixtures\Tasks\CronCallTask;

/**
 * 测试用示例插件：用 addCron() 命令式登记两条定时任务（闭包 + 类方法元组）。
 *
 * 两条任务名故意与任务名冲突用例的探针重名（cron.plugin.closure / cron.plugin.method），
 * 以便复用同一个插件验证「重复名必须显式抛错」。
 */
final class CronPlugin implements PluginInterface
{
    public function name(): string
    {
        return 'cron-plugin';
    }

    public function register(PluginManager $manager): void
    {
    }

    public function boot(PluginManager $manager): void
    {
        $manager->addCron(
            '* * * * *',
            static function (): void {
            },
            'cron.plugin.closure',
            '闭包式任务（插件 boot 期登记）'
        );

        $manager->addCron(
            '0 3 * * *',
            [CronCallTask::class, 'handle'],
            'cron.plugin.method',
            '类方法式任务（走容器解析）'
        );
    }
}
