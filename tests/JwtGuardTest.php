<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Auth\JwtGuard;
use Kode\Jwt\KodeJwt;
use PHPUnit\Framework\TestCase;

/**
 * JWT 守卫回归测试。
 *
 * 重点守护 H-1 安全修复：连续签发不能复用同一个 jti，也不能泄漏前次声明的 claims。
 */
final class JwtGuardTest extends TestCase
{
    private function guard(): JwtGuard
    {
        return new JwtGuard([
            'guards' => [
                'api' => [
                    'driver' => 'sso',
                    'storage' => 'memory',
                    'algo' => 'HS256',
                    'secret' => 'test-secret',
                    'ttl' => 3600,
                    'platform' => 'web',
                ],
            ],
        ]);
    }

    public function testIssueReturnsParseableToken(): void
    {
        $guard = $this->guard();
        $token = $guard->issue(['uid' => 1, 'sub' => 'u1']);

        self::assertIsString($token);
        self::assertNotEmpty($token);

        $payload = $guard->authenticate($token);
        self::assertSame('1', (string) $payload->uid);
    }

    public function testConsecutiveIssuesHaveDistinctJti(): void
    {
        $guard = $this->guard();

        $t1 = $guard->issue(['uid' => 1001, 'roles' => ['user']]);
        $t2 = $guard->issue(['uid' => 2002, 'roles' => ['admin']]);

        $p1 = KodeJwt::guard('api')->authenticate($t1);
        $p2 = KodeJwt::guard('api')->authenticate($t2);

        // jti 必须互不相同（旧实现会复用首签 jti）。
        self::assertNotSame($p1->jti, $p2->jti);

        // 后签的令牌不能泄漏前次声明的 claims（旧实现会把 user 泄漏到 admin 令牌）。
        self::assertSame(['user'], $p1->roles);
        self::assertSame(['admin'], $p2->roles);
        self::assertNotContains('user', $p2->roles);
    }

    public function testInvalidateRevokesToken(): void
    {
        $guard = $this->guard();
        $token = $guard->issue(['uid' => 42]);

        self::assertTrue($guard->invalidate($token));

        $this->expectException(\Kode\Jwt\Exception\JwtException::class);
        $guard->authenticate($token);
    }
}
