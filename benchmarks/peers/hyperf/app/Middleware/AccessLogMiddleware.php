<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * 访问日志中间件（对标 kode/config/logging.php access_log 开启态 + webman 同构中间件）。
 *
 * 每请求记录 method/uri/status/延迟（worker 级文件句柄复用）。
 */
class AccessLogMiddleware implements MiddlewareInterface
{
    /** @var resource|null */
    private static $fh;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $t0 = microtime(true);
        $response = $handler->handle($request);
        $us = (int) ((microtime(true) - $t0) * 1_000_000);

        if (self::$fh === null) {
            self::$fh = @fopen(sys_get_temp_dir() . '/hyperf_access.log', 'a');
        }
        if (self::$fh) {
            fwrite(self::$fh, sprintf(
                "%s %s %d %d\n",
                $request->getMethod(),
                $request->getUri()->getPath(),
                $response->getStatusCode(),
                $us
            ));
        }

        return $response;
    }
}
