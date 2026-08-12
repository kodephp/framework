<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Middleware;

use Kode\Context\Context;
use Kode\Framework\Contracts\AuthGuard;
use Kode\Framework\Http\Resp;
use Kode\Http\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * JWT 鉴权中间件（示例）
 *
 * 从 Authorization: Bearer <token> 解析令牌，失败返回 401；
 * 成功后把解析出的载荷塞进请求属性 `auth`，下游控制器用
 *   Request::attr('auth') 即可取到当前用户。
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = Request::bearerToken();
        if ($token === null || $token === '') {
            return $this->unauthorized('缺少访问令牌');
        }

        try {
            // 仅依赖 AuthGuard 契约，不依赖具体 JWT 库；更换鉴权方案改绑定即可。
            $payload = resolve(AuthGuard::class)->authenticate($token);
        } catch (\Throwable) {
            return $this->unauthorized('令牌无效或已过期');
        }

        $request = $request->withAttribute('auth', $payload);

        // 把用户标识写入 kode/context（按执行单元隔离），供审计中间件合规记录。
        // 审计读取后会清除，避免多进程顺序处理时跨请求泄漏（默认审计开启即被清除）。
        $userId = $payload->getSubject() ?? $payload->getUserIdentifier();
        if ($userId !== null) {
            Context::set('auth_user_id', (string) $userId);
        }

        // 回写「当前请求」，使下游控制器可通过 Request::attr('auth') 取到载荷。
        Request::setRequest($request);

        return $handler->handle($request);
    }

    private function unauthorized(string $message): ResponseInterface
    {
        return Resp::error($message, 401);
    }
}
