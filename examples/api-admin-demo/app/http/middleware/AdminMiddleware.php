<?php

declare(strict_types=1);

namespace app\http\middleware;

use Kode\Framework\Http\Middleware;
use Kode\Framework\Http\Request;
use Kode\Framework\Http\Resp;

/**
 * 后台角色中间件：AuthMiddleware 之后执行，校验当前 JWT 是否具备 admin 角色。
 */
final class AdminMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): mixed
    {
        /** @var \Kode\Jwt\Token\Payload|null $payload */
        $payload = $request->attr('auth');

        if ($payload === null) {
            return Resp::error('未认证', 401);
        }

        $roles = $payload->roles ?? [];
        if (!in_array('admin', $roles, true)) {
            return Resp::error('无权限访问后台', 403);
        }

        return $next($request);
    }
}
