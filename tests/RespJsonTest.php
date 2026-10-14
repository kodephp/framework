<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Http\Resp;
use Kode\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * 统一响应信封单元测试（http.envelope 默认开）：Resp::json / Resp::error / Resp::make / raw 变体。
 *
 * 信封结构：{code, msg, data, ...extra}——code 默认等于 HTTP 状态码，可传自定义业务码；
 * 分页额外附带 total/page/per_page/last_page（与 code/msg 同级，业务数据在 data 下）。
 */
final class RespJsonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Resp::envelope(true);
    }

    protected function tearDown(): void
    {
        Resp::envelope(true);
        parent::tearDown();
    }

    private function decode(Response $resp): array
    {
        return json_decode((string) $resp->getBody(), true);
    }

    public function testJsonWrapsDataInEnvelope(): void
    {
        $resp = Resp::json(['id' => 1, 'name' => 'Kode']);
        $body = $this->decode($resp);

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame(200, $body['code']);
        self::assertSame('ok', $body['msg']);
        self::assertSame(['id' => 1, 'name' => 'Kode'], $body['data']);
        self::assertSame('1', $resp->getHeaderLine('X-Kode-Envelope'));
    }

    public function testJsonAcceptsCustomBusinessCode(): void
    {
        $resp = Resp::json(['balance' => 100], 200, code: 10001);
        $body = $this->decode($resp);

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame(10001, $body['code']);
        self::assertSame(['balance' => 100], $body['data']);
    }

    public function testJsonNullDataIsNullInEnvelope(): void
    {
        $resp = Resp::json(null);
        $body = $this->decode($resp);

        self::assertSame(200, $body['code']);
        self::assertNull($body['data']);
    }

    public function testErrorReturnsEnvelopeWithStatusAsCode(): void
    {
        $resp = Resp::error('参数错误', 400);
        $body = $this->decode($resp);

        self::assertSame(400, $resp->getStatusCode());
        self::assertSame(400, $body['code']);
        self::assertSame('参数错误', $body['msg']);
        self::assertNull($body['data']);
    }

    public function testErrorAcceptsCustomBusinessCode(): void
    {
        $resp = Resp::error('余额不足', 400, code: 10002);
        $body = $this->decode($resp);

        self::assertSame(400, $resp->getStatusCode());
        self::assertSame(10002, $body['code']);
        self::assertSame('余额不足', $body['msg']);
    }

    public function testErrorMergesExtraFieldsAtRoot(): void
    {
        $resp = Resp::error('校验失败', 422, ['errors' => [['field' => 'email', 'message' => '非法']]]);
        $body = $this->decode($resp);

        self::assertSame(422, $resp->getStatusCode());
        self::assertSame(422, $body['code']);
        self::assertSame('email', $body['errors'][0]['field']);
        self::assertNull($body['data']);
    }

    public function testPaginatePutsPaginationFieldsAtRoot(): void
    {
        $resp = Resp::paginate([['id' => 1]], 30, 2, 10);
        $body = $this->decode($resp);

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame(200, $body['code']);
        self::assertSame([['id' => 1]], $body['data']);
        self::assertSame(30, $body['total']);
        self::assertSame(2, $body['page']);
        self::assertSame(10, $body['per_page']);
        self::assertSame(3, $body['last_page']);
    }

    public function testPaginateFromAcceptsDbResultArray(): void
    {
        // 模拟 kode/database QueryBuilder::paginate() 返回结构。
        $dbResult = [
            'items'        => [['id' => 1], ['id' => 2]],
            'total'        => 42,
            'per_page'     => 15,
            'current_page' => 2,
            'last_page'    => 3,
        ];

        $resp = Resp::paginateFrom($dbResult);
        $body = $this->decode($resp);

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame(200, $body['code']);
        self::assertSame([['id' => 1], ['id' => 2]], $body['data']);
        self::assertSame(42, $body['total']);
        self::assertSame(2, $body['page']);
        self::assertSame(15, $body['per_page']);
        self::assertSame(3, $body['last_page']);
    }

    public function testPaginateFromFallsBackGracefullyOnMissingKeys(): void
    {
        $resp = Resp::paginateFrom(['items' => [['id' => 9]]]);
        $body = $this->decode($resp);

        self::assertSame(1, $body['total']);
        self::assertSame(1, $body['page']);
        self::assertSame(1, $body['last_page']);
    }

    public function testEnvelopeOffFallsBackToRawMode(): void
    {
        Resp::envelope(false);

        $ok = $this->decode(Resp::json(['id' => 1]));
        self::assertSame(['id' => 1], $ok);
        self::assertArrayNotHasKey('code', $ok);

        $err = $this->decode(Resp::error('参数错误', 400));
        self::assertSame('参数错误', $err['message']);
        self::assertArrayNotHasKey('code', $err);

        Resp::envelope(true);
    }

    public function testRawJsonBypassesEnvelopeEvenWhenEnabled(): void
    {
        $resp = Resp::rawJson(['status' => 'ok']);
        $body = $this->decode($resp);

        self::assertSame(['status' => 'ok'], $body);
        self::assertArrayNotHasKey('code', $body);
        self::assertSame('passthrough', $resp->getHeaderLine('X-Kode-Envelope'));
    }

    public function testMakeBuildsJsonResponseBodyWithoutEnvelope(): void
    {
        $resp = Resp::make(['id' => 1], 201, ['X-Trace' => 'abc']);
        $body = $this->decode($resp);

        self::assertSame(201, $resp->getStatusCode());
        self::assertSame(['id' => 1], $body);
        self::assertSame('abc', $resp->getHeaderLine('X-Trace'));
        self::assertSame('passthrough', $resp->getHeaderLine('X-Kode-Envelope'));
    }
}
