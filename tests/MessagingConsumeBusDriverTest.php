<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Console\Commands\MessagingConsumeCommand;
use PHPUnit\Framework\TestCase;

/**
 * messaging:consume 的总线驱动取值（v1.7.7）。
 *
 * 生产端 messaging()->pubsub() 的缺省驱动来自 messaging.pubsub.default；
 * 消费端过去只读顶层 default 再硬落 'memory'，两边口径不同就会出现
 * 「发布进 redis、消费挂在 memory」——进程活着、日志干净、消息永远收不到。
 */
final class MessagingConsumeBusDriverTest extends TestCase
{
    private function driver(array $config, ?string $option = null): string
    {
        return MessagingConsumeCommand::busDriver($config, $option);
    }

    public function testPubsubDefaultIsHonouredWhenTopLevelMissing(): void
    {
        self::assertSame('redis', $this->driver(['pubsub' => ['default' => 'redis']]));
    }

    public function testPubsubDefaultIsHonouredWhenTopLevelBlank(): void
    {
        // env('MESSAGING_DEFAULT', '') 写出的空串必须按「未配」处理
        self::assertSame('redis', $this->driver([
            'default' => '',
            'pubsub' => ['default' => 'redis'],
        ]));
    }

    public function testTopLevelDefaultStillWinsOverPubsubDefault(): void
    {
        self::assertSame('channel', $this->driver([
            'default' => 'channel',
            'pubsub' => ['default' => 'redis'],
        ]));
    }

    public function testCommandLineOptionWinsOverConfig(): void
    {
        self::assertSame('kafka', $this->driver(
            ['default' => 'channel', 'pubsub' => ['default' => 'redis']],
            'kafka',
        ));
    }

    public function testBlankOptionFallsThrough(): void
    {
        self::assertSame('redis', $this->driver(['pubsub' => ['default' => 'redis']], '   '));
    }

    public function testNonStringCandidatesAreIgnored(): void
    {
        // 结构化/异常值不得被当成驱动名（(string) 强转会得到 'Array' 之类的幽灵驱动）
        self::assertSame('memory', $this->driver([
            'default' => ['redis'],
            'pubsub' => ['default' => 42],
        ]));
    }

    public function testNothingConfiguredFallsBackToMemory(): void
    {
        self::assertSame('memory', $this->driver([]));
        self::assertSame('memory', $this->driver(['pubsub' => []]));
    }
}
