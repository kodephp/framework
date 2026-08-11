<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Http\Resp;
use Kode\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * 标准响应（无信封）单元测试：Resp::json / Resp::error / Resp::auto。
 */
final class RespJsonTest extends TestCase
{
    private function decode(Response $resp): array
    {
        return json_decode((string) $resp->getBody(), true);
    }

    public function testJsonReturnsRawDataWithoutEnvelope(): void
    {
        $resp = Resp::json(['id' => 1, 'name' => 'Kode']);
        $body = $this->decode($resp);

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame(['id' => 1, 'name' => 'Kode'], $body);
        self::assertArrayNotHasKey('code', $body);
        self::assertArrayNotHasKey('msg', $body);
        self::assertArrayNotHasKey('data', $body);
    }

    public function testJsonNullBecomesEmptyObject(): void
    {
        $resp = Resp::json(null);
        self::assertSame('{}', trim((string) $resp->getBody()));
    }

    public function testErrorReturnsStandardBodyAndStatus(): void
    {
        $resp = Resp::error('参数错误', 400);
        $body = $this->decode($resp);

        self::assertSame(400, $resp->getStatusCode());
        self::assertSame('参数错误', $body['message']);
        self::assertArrayNotHasKey('code', $body);
    }

    public function testErrorMergesExtraFields(): void
    {
        $resp = Resp::error('校验失败', 422, ['errors' => [['field' => 'email', 'message' => '非法']]]);
        $body = $this->decode($resp);

        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('email', $body['errors'][0]['field']);
        self::assertArrayNotHasKey('data', $body);
    }

    public function testAutoFollowsEnvelopeConfig(): void
    {
        // envelope=false（默认）→ 标准 JSON
        $resp = Resp::auto(['id' => 1], 'ok', 0, 200);
        self::assertSame(['id' => 1], $this->decode($resp));
    }
}
