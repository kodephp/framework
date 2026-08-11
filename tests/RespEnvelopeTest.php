<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Http\Resp;
use Kode\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * 统一响应体（{code, msg, data} 信封）单元测试。
 */
final class RespEnvelopeTest extends TestCase
{
    public function testOkEnvelope(): void
    {
        $resp = Resp::ok(['id' => 1], '创建成功');
        $body = $this->json($resp);

        self::assertSame(0, $body['code']);
        self::assertSame('创建成功', $body['msg']);
        self::assertSame(['id' => 1], $body['data']);
        self::assertSame(200, $resp->getStatusCode());
    }

    public function testFailEnvelope(): void
    {
        $resp = Resp::fail('参数错误', 'E400', 400);
        $body = $this->json($resp);

        self::assertSame('E400', $body['code']);
        self::assertSame('参数错误', $body['msg']);
        self::assertArrayNotHasKey('data', $body);
        self::assertSame(400, $resp->getStatusCode());
    }

    public function testFailWithExtraData(): void
    {
        $resp = Resp::fail('校验失败', 'E422', 422, ['errors' => [['field' => 'email', 'message' => '非法']]]);
        $body = $this->json($resp);

        self::assertSame('E422', $body['code']);
        self::assertArrayHasKey('data', $body);
        self::assertSame('email', $body['data']['errors'][0]['field']);
    }

    public function testPaginateEnvelope(): void
    {
        $resp = Resp::paginate([['id' => 1]], 1, 1, 10);
        $body = $this->json($resp);

        self::assertSame(0, $body['code']);
        self::assertSame([['id' => 1]], $body['data']['items']);
        self::assertSame(1, $body['data']['pagination']['total']);
        self::assertSame(1, $body['data']['pagination']['total_page']);
    }

    public function testMakeChains(): void
    {
        $resp = Resp::make(['k' => 'v'], 201)
            ->header('X-Trace', 'abc')
            ->withCors()
            ->withSecurity();

        self::assertSame(201, $resp->getStatusCode());
        self::assertSame('abc', $resp->getHeaderLine('X-Trace'));
        self::assertSame('*', $resp->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('nosniff', $resp->getHeaderLine('X-Content-Type-Options'));
    }

    private function json(Response $resp): array
    {
        return json_decode((string) $resp->getBody(), true);
    }
}
