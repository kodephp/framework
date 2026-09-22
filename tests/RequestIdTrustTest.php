<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

/**
 * X-Request-Id 信任边界：config `security.request_id_allow_client` 决定入站同名头能否被沿用。
 *
 * 该值原样进访问日志与审计，若对外入口允许调用方自带，等于把链路关联键交给攻击者
 * （伪造/关联他人请求）。此前配置里写着开关、代码恒走「信任客户端」，本类锁定接线。
 */
final class RequestIdTrustTest extends TestCase
{
    /** 两种开关互斥，必须重建应用实例防串扰。 */
    protected bool $independentApp = true;

    public function testClientSuppliedRequestIdIsIgnoredWhenNotTrusted(): void
    {
        $this->configOverrides = ['security' => [
            'request_id' => true,
            'request_id_allow_client' => false,
        ]];
        $this->bootApp();

        $id = $this->get('/ping', ['X-Request-Id' => 'attacker-controlled-id'])->header('X-Request-Id');

        $this->assertNotSame('attacker-controlled-id', $id);
        // kode/http 自生成形态：24 位十六进制。
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/', (string) $id);
    }

    public function testClientSuppliedRequestIdIsReusedWhenTrusted(): void
    {
        $this->configOverrides = ['security' => [
            'request_id' => true,
            'request_id_allow_client' => true,
        ]];
        $this->bootApp();

        $id = $this->get('/ping', ['X-Request-Id' => 'trace-from-gateway'])->header('X-Request-Id');

        $this->assertSame('trace-from-gateway', $id, '跨服务透传链路 ID 是默认（信任）语义');
    }
}
