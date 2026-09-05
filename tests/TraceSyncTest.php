<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Context\Context;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request;

/**
 * 追踪关闭时跳过入站链路头同步（热路径杀开关）。
 *
 * observability.tracing.enabled=false 的引导须关闭 kode/http 的逐请求嗅探；
 * 开启时保持同步（TraceMiddleware / 日志关联 / 异常 tracer 桥接依赖它）。
 * 开关为进程级静态量，每次引导按本进程配置重设（本类 independentApp 隔离）。
 */
final class TraceSyncTest extends TestCase
{
    /** 各用例配置互斥，必须重建 CoreApp 单例防串扰。 */
    protected bool $independentApp = true;

    public function testTraceSyncDisabledWhenTracingOff(): void
    {
        $this->configOverrides = ['observability' => ['tracing' => ['enabled' => false]]];
        $this->bootApp();

        // 同进程套件中其他用例可能已写入追踪键，先清零以隔离断言。
        foreach ([Context::REQUEST_ID, Context::TRACE_ID, Context::SPAN_ID, Context::CORRELATION_ID] as $key) {
            Context::delete($key);
        }

        Request::setRequest(new ServerRequest('GET', 'http://x.com/ping', [], ['X-Trace-Id' => 't-1']));

        $this->assertNull(Context::get(Context::TRACE_ID));
        // 请求本体仍正常预置。
        $this->assertNotNull(Request::getRequest());
    }

    public function testTraceSyncEnabledWhenTracingOn(): void
    {
        $this->configOverrides = ['observability' => ['tracing' => ['enabled' => true]]];
        $this->bootApp();

        Request::setRequest(new ServerRequest('GET', 'http://x.com/ping', [], ['X-Trace-Id' => 't-2']));

        $this->assertSame('t-2', Context::get(Context::TRACE_ID));
    }
}
