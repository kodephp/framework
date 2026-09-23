<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\DI\Contract\ContainerInterface as DiContainer;
use Kode\Messaging\Messaging;
use Psr\Container\ContainerInterface;

/**
 * 启动消息消费进程（修复「框架最大坑」：此前 MessagingServiceProvider 只绑定了
 * 生产者 Messenger，ConsoleServiceProvider 也没有任何消费命令，导致订阅型消息
 * （事件总线 / 跨进程通知）在生产环境无法被消费）。
 *
 *   kode console messaging:consume                       # 消费 messaging.consumers 全部频道
 *   kode console messaging:consume --channel=orders:created
 *   kode console messaging:consume --driver=redis        # 跨进程消费（需 redis 总线）
 *
 * 多频道且支持 pcntl 时，会为每个频道 fork 一个子进程；否则仅消费首个频道并提示。
 * 处理器类约定：提供 handle(array $payload) / __invoke / run / execute 之一即可。
 */
#[AsCommand(
    name: 'messaging:consume',
    description: '启动消息消费进程（订阅 messaging.consumers 配置的频道）',
    usage: 'messaging:consume {--channel= : 仅订阅该频道} {--driver= : 覆盖 messaging 总线驱动}',
)]
final class MessagingConsumeCommand extends Command
{
    /** 本命令认识的选项 */
    private const OPTS = ['channel', 'driver'];

    /** @var list<string> 处理器约定方法（按优先级） */
    private const array HANDLER_METHODS = ['handle', '__invoke', 'run', 'execute'];

    protected function handle(): int
    {
        if (($bad = $this->rejectUnknownOptions(self::OPTS)) !== null) {
            return $bad;
        }

        /** @var array<string, mixed> $config */
        $config = (array) config('messaging', []);
        Messaging::configure($config);

        /** @var array<string, class-string> $consumers */
        $consumers = (array) config('messaging.consumers', []);
        if ($consumers === []) {
            $this->error('messaging.consumers 为空：请在 config/messaging.php 配置 channel => 处理器类。');

            return 1;
        }

        $channel = $this->opt('channel');
        if ($channel !== null && $channel !== '' && !isset($consumers[$channel])) {
            $this->error("未配置的频道：{$channel}");

            return 1;
        }

        $picked = ($channel !== null && $channel !== '')
            ? [$channel => $consumers[$channel]]
            : $consumers;

        $driver = self::busDriver($config, $this->opt('driver'));

        if (count($picked) > 1 && $this->canFork()) {
            return $this->runForked($picked, $driver);
        }

        if (count($picked) > 1) {
            $first = array_key_first($picked);
            $this->warn(sprintf(
                '检测到 %d 个频道但未指定 --channel 且无法 fork，仅消费首个频道 %s。'
                    . '建议为每个频道单独启动 messaging:consume --channel=xxx。',
                count($picked),
                $first,
            ));

            return $this->consumeOne($first, $picked[$first], $driver);
        }

        $onlyChannel = array_key_first($picked);

        return $this->consumeOne($onlyChannel, $picked[$onlyChannel], $driver);
    }

    /**
     * 消费进程连哪条总线：--driver > messaging.default > messaging.pubsub.default > memory。
     *
     * pubsub.default 是生产端 messaging()->pubsub() 实际使用的缺省驱动；此前消费端只认
     * 顶层 default 再落 'memory'，于是「生产在 redis、消费在 memory」——两边都不报错，
     * 消息永远收不到。空串一律按「未配」处理（env('MESSAGING_DEFAULT', '') 很常见），
     * 否则会把 '' 当成驱动名交给 Messaging::pubsub() 去建实例。
     *
     * @param array<string, mixed> $config
     */
    public static function busDriver(array $config, ?string $option = null): string
    {
        $pubsub = (array) ($config['pubsub'] ?? []);

        foreach ([$option, $config['default'] ?? null, $pubsub['default'] ?? null] as $candidate) {
            $candidate = is_string($candidate) ? trim($candidate) : '';

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'memory';
    }

    /**
     * 单进程消费一个频道。
     */
    private function consumeOne(string $channel, string $handlerClass, string $driver): int
    {
        // 驱动参数由 Messaging::pubsub() 自己按 pubsub.<driver> 取；此前传的是顶层
        // $config[$driver]（那是 ws/sse/cluster 的协议段），等于拿集群配置去覆盖总线配置。
        $bus = Messaging::pubsub($driver);

        $handler = $this->resolveHandler($handlerClass);
        $method = $this->pickMethod($handler);

        $bus->subscribe($channel, function (array $payload, mixed $ack) use ($handler, $method): void {
            $handler->{$method}($payload);
        });

        $this->info(sprintf(
            'messaging 消费进程启动：频道=%s 驱动=%s（Ctrl+C / SIGTERM 优雅退出）',
            $channel,
            $bus->driver(),
        ));

        if (method_exists($bus, 'loop')) {
            $bus->loop();

            return 0;
        }

        $this->warn(sprintf(
            '驱动 %s 不支持阻塞消费（仅进程内可用），以空闲循环保持进程存活；'
                . '如需跨进程消费请改用 redis 等持久化总线。',
            $bus->driver(),
        ));

        $stop = false;
        $onSignal = static function () use (&$stop): void {
            $stop = true;
        };
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, $onSignal);
            pcntl_signal(SIGINT, $onSignal);
        }
        while (! $stop) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            usleep(200000);
        }

        return 0;
    }

    /**
     * 为每个频道 fork 一个子进程常驻消费（需 ext-pcntl + ext-posix）。
     *
     * @param array<string, class-string> $picked
     */
    private function runForked(array $picked, string $driver): int
    {
        $pids = [];
        foreach ($picked as $channel => $handlerClass) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->error('fork 失败，无法并行消费多频道。');

                return 1;
            }
            if ($pid === 0) {
                exit($this->consumeOne($channel, $handlerClass, $driver));
            }
            $pids[$pid] = $channel;
        }

        $stop = false;
        $onSignal = static function () use (&$stop): void {
            $stop = true;
        };
        pcntl_signal(SIGTERM, $onSignal);
        pcntl_signal(SIGINT, $onSignal);

        while (! $stop && $pids !== []) {
            $status = null;
            $pid = pcntl_wait($status, WNOHANG);
            if ($pid > 0) {
                unset($pids[$pid]);
            }
            pcntl_signal_dispatch();
            usleep(200000);
        }

        foreach (array_keys($pids) as $pid) {
            posix_kill($pid, SIGTERM);
        }

        return 0;
    }

    /**
     * @param class-string $class
     */
    private function resolveHandler(string $class): object
    {
        try {
            $container = $this->container();
            if ($container->has($class)) {
                /** @var object $instance */
                $instance = $container->make($class);

                return $instance;
            }
        } catch (\Throwable) {
            // 容器不可用，走反射
        }

        if (! class_exists($class)) {
            throw new \RuntimeException("消息处理器类不存在：{$class}");
        }

        return new $class();
    }

    private function pickMethod(object $instance): string
    {
        foreach (self::HANDLER_METHODS as $method) {
            if (method_exists($instance, $method)) {
                return $method;
            }
        }

        throw new \RuntimeException(sprintf(
            '消息处理器 %s 缺少 %s 中任意一个处理方法',
            $instance::class,
            implode(' / ', self::HANDLER_METHODS),
        ));
    }

    private function canFork(): bool
    {
        return function_exists('pcntl_fork')
            && function_exists('posix_kill')
            && function_exists('pcntl_wait');
    }

    private function container(): DiContainer
    {
        /** @var DiContainer $container */
        $container = resolve(DiContainer::class);

        return $container;
    }
}
