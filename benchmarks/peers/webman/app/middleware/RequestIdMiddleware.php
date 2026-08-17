<?php

declare(strict_types=1);

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 链路 ID 中间件（对标 kode/config/security.php request_id 开启态）。
 * 客户端未带 X-Request-Id 时生成随机 ID 并回写响应头，便于跨服务串联与日志关联。
 *
 * 注意：webman 的 Response::with* 是 PSR-7 不可变接口，必须接收返回值才生效。
 */
class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        try {
            $id = (string) ($request->header('x-request-id') ?: bin2hex(random_bytes(12)));
            $response = $handler($request);

            return $response->withHeader('X-Request-Id', $id);
        } catch (\Throwable $e) {
            file_put_contents('/tmp/mw_err.txt', "RequestId: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
            throw $e;
        }
    }
}
