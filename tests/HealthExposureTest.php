<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

/**
 * 健康端点的对外口径：
 *  - 明细脱敏：未鉴权的 /health 对外只暴露状态，探针失败文本
 *    （常含 DB/缓存底层报错）在非 debug 下收敛为 `error`；
 *  - 聚合状态：`status` 必须跟探针同源（任一 error 即 `degraded`），但 HTTP 恒 200 ——
 *    这条是给人和监控面板读的报表，闸门在 /health/ready。
 */
final class HealthExposureTest extends TestCase
{
    /** 各用例配置互斥，必须重建 CoreApp 单例防串扰。 */
    protected bool $independentApp = true;

    private const MARKER = 'S3CR3T-db-dsn-marker';

    private function bootWithBoom(bool $debug): void
    {
        $this->bootWithChecks([
            'boom' => static function (): string {
                throw new \RuntimeException('boom ' . self::MARKER);
            },
        ], $debug);
    }

    /**
     * @param array<string, callable> $checks health.checks 探针表
     */
    private function bootWithChecks(array $checks, bool $debug = false): void
    {
        $overrides = ['health' => ['checks' => $checks]];
        if ($debug) {
            $overrides['app'] = [
                'name' => 'test-app',
                'env' => 'test',
                'debug' => true,
                'timezone' => 'UTC',
                'required' => ['app.name', 'app.env'],
                'providers' => [],
                'runtime' => ['fiber'],
            ];
        }
        $this->configOverrides = $overrides;
        $this->bootApp();
    }

    public function testFailingProbeDetailsHiddenWhenDebugOff(): void
    {
        $this->bootWithBoom(false);

        $r = $this->get('/health');
        $r->assertStatus(200);
        $this->assertStringNotContainsString(self::MARKER, $r->body());
        $this->assertStringContainsString('"boom":"error"', $r->body());
    }

    public function testFailingProbeDetailsShownWhenDebugOn(): void
    {
        $this->bootWithBoom(true);

        $r = $this->get('/health');
        $r->assertStatus(200);
        $this->assertStringContainsString(self::MARKER, $r->body());
    }

    public function testAggregateStatusFollowsTheProbesButKeepsHttp200(): void
    {
        $this->bootWithBoom(false);

        $r = $this->get('/health');
        // 状态与探针同源：components 里有 error 时顶层不许说 ok。
        $this->assertSame('degraded', $r->json()['status'], '探针已翻红，/health 的 status 仍恒为 ok');
        // HTTP 码保持 200：/health 是报表不是闸门（摘流看 /health/ready）。
        // 翻成 503 会让「拿 /health 当 livenessProbe」的应用在一个外部依赖抖动时开始无意义重启。
        $r->assertStatus(200);
    }

    public function testAggregateStatusIsOkWhenProbesPass(): void
    {
        // 正对照：上一条只证明「坏时不再说 ok」；如果 status 被写死成 degraded，那条也全绿。
        $this->bootWithChecks(['ping' => static fn (): string => 'ok']);

        $r = $this->get('/health');
        $r->assertStatus(200);
        $this->assertSame('ok', $r->json()['status']);
        $this->assertSame('ok', $r->json()['components']['ping']);
    }
}
