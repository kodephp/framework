<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Kode\Framework\Http\Controller;

/**
 * 演示 kode 生态能力（缓存 / 事件 / 日志）的集成。
 */
final class DemoController extends Controller
{
    public function cache()
    {
        $hits = cache()->remember('kode:demo:hits', static function (): int {
            return random_int(1, 100);
        }, 60);

        return $this->ok([
            'driver' => cache()->getDefaultDriver(),
            'hits' => $hits,
        ], '来自缓存');
    }

    public function fireEvent()
    {
        logger()->info('演示事件派发');

        event(new \App\Events\PingEvent('pong'));

        return $this->ok(['event' => 'App\Events\PingEvent'], '事件已派发');
    }

    public function aop()
    {
        // 通过 Aop::proxy 获取织入日志切面后的代理对象
        $greeter = \Kode\Aop\Aop::proxy(\App\Services\Greeter::class);
        $message = $greeter->hello('kode');

        return $this->ok(['message' => $message], 'AOP 已织入');
    }

    /**
     * 并发运行时演示。
     *
     * 通过 kode/core 的 runtime() 助手拿到统一运行时（Runtime），它向下桥接
     * kode/fibers（单进程协程）、kode/process（多进程）、kode/parallel（ZTS 多线程）
     * 与分布式。启用哪些模式由 config/app.php 的 runtime 决定；
     * 本框架默认 fiber，可叠加 process / parallel。
     */
    public function concurrent()
    {
        $rt = runtime();

        $results = $rt->runTasks([
            static fn() => 'hello-from-task-1',
            static fn() => array_sum([1, 2, 3, 4, 5]),
            static fn() => date('H:i:s'),
        ]);

        return $this->ok([
            'runtime'       => $rt->getName(),
            'enabled_modes' => array_keys($rt->enabled()),
            'available'     => [
                'fiber'      => $rt->supported('fiber'),
                'process'    => $rt->supported('process'),
                'parallel'   => $rt->supported('parallel'),
                'distributed' => $rt->supported('distributed'),
            ],
            'task_results'  => $results,
        ], '并发演示（' . $rt->getName() . ' 运行时）');
    }

    /**
     * 限流演示（kode/limiting）。
     *
     * 直接消耗一次额度并返回剩余量；连续快速请求可见 remaining 递减。
     * 另见 /demo/rate-limit（挂了 RateLimitMiddleware，超限返回 429）。
     */
    public function rateLimit($req)
    {
        $ip = $req->getServerParams()['REMOTE_ADDR'] ?? 'local';
        $result = rateLimit()->consume('demo:rate-limit:' . $ip, 1);

        return $this->ok([
            'allowed'    => $result->isAllowed(),
            'remaining'  => $result->remaining,
            'limit'      => $result->limit,
            'retry_after' => $result->retryAfter,
        ], '限流演示');
    }

    /**
     * 数据库适配器演示（kode/database）。
     *
     * 仅展示配置已加载、连接懒初始化，不建立真实数据库连接。
     */
    public function db()
    {
        /** @var array<string, mixed> $conf */
        $conf = \Kode\Database\Db\Db::getConfig();

        return $this->ok([
            'loaded'           => true,
            'default'          => $conf['default'] ?? null,
            'connections'      => array_keys($conf['connections'] ?? []),
            'read_write_split' => $conf['read_write_split'] ?? false,
        ], '数据库适配器已接入（kode/database）');
    }

    /**
     * HTTP 客户端演示（kode/http-client，PSR-18）。
     *
     * 向示例接口发请求，返回状态码与解析后的 JSON（失败则降级到本地回声）。
     */
    public function httpClient()
    {
        try {
            $resp = http()->get('https://example.com/');
            $body = $resp->getBody()->getContents();

            return $this->ok([
                'status' => $resp->getStatusCode(),
                'length' => strlen($body),
                'driver' => get_class(http()),
            ], 'HTTP 客户端请求成功（kode/http-client）');
        } catch (\Throwable $e) {
            return $this->ok([
                'error' => $e->getMessage(),
            ], 'HTTP 客户端演示（网络不可达时降级）');
        }
    }

    /**
     * 消息总线演示（kode/messaging，进程内 memory 总线）。
     */
    public function message()
    {
        $received = [];
        $bus = messaging()->bus('memory');
        $bus->subscribe('demo:ping', static function (array $payload) use (&$received): void {
            $received[] = $payload;
        });

        $bus->publish('demo:ping', ['hello' => 'kode']);

        return $this->ok([
            'bus' => 'memory',
            'received' => $received,
        ], '消息总线演示（kode/messaging）');
    }

    /**
     * 熔断器演示（框架中性 InMemoryBreaker，运行时无关）。
     *
     * 默认：下游正常，返回数据，状态 closed。
     * ?fail=1：连续触发下游失败，达到阈值后熔断器打开，
     *          后续请求不再打到下游，直接走 fallback 降级（不抛错）。
     * 一次请求内顺序演示「失败→熔断→降级」全过程（单 worker 内状态确定）。
     *
     * 通用写法（保护第三方依赖）：
     *   breaker()->run('user-service', fn () => http()->get(...), fallback: fn () => ...);
     */
    public function breakerDemo()
    {
        $name = 'demo-downstream';
        $fail = (bool) ($this->query('fail') ?? false);

        if (!$fail) {
            $result = breaker()->run($name, static fn() => ['data' => 'ok'],
                fallback: static fn() => ['degraded' => true]);

            return $this->ok([
                'result' => $result,
                'state'  => breaker()->state($name),
            ], '熔断器演示（breaker()->run）');
        }

        // 连续失败直到熔断打开，随后请求走降级
        $trace = [];
        for ($i = 0; $i < 7; $i++) {
            try {
                $trace[] = breaker()->run($name,
                    static fn() => throw new \RuntimeException('downstream timeout'),
                    fallback: static fn() => ['degraded' => true]
                );
            } catch (\RuntimeException $e) {
                $trace[] = ['error' => $e->getMessage()];
            }
        }

        return $this->ok([
            'trace'       => $trace,
            'final_state' => breaker()->state($name),
        ], '熔断器演示（连续失败 → 熔断 → 降级）');
    }

    /**
     * 国际化演示（symfony/translation + lang()）。
     *
     * 语种由 Accept-Language 中间件自动切换（也可 ?locale=en 手动覆盖）。
     */
    public function i18nDemo()
    {
        $name = (string) ($this->query('name') ?? 'Kode');
        $locale = $this->query('locale');
        if (is_string($locale)) {
            translator()->setLocale($locale);
        }

        return $this->ok([
            'locale' => translator()->getLocale(),
            'welcome' => lang('welcome', ['name' => $name]),
            'missing' => lang('user_not_found', ['id' => 42]),
        ], '国际化演示（lang / Accept-Language）');
    }
}
