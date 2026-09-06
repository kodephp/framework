<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

/**
 * 健康端点明细脱敏：未鉴权的 /health 对外只暴露状态，探针失败文本
 *（常含 DB/缓存底层报错）在非 debug 下收敛为 `error`。
 */
final class HealthExposureTest extends TestCase
{
    /** 各用例配置互斥，必须重建 CoreApp 单例防串扰。 */
    protected bool $independentApp = true;

    private const MARKER = 'S3CR3T-db-dsn-marker';

    private function bootWithBoom(bool $debug): void
    {
        $overrides = ['health' => ['checks' => [
            'boom' => static function (): string {
                throw new \RuntimeException('boom ' . self::MARKER);
            },
        ]]];
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
}
