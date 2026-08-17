<?php

declare(strict_types=1);

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 访问日志中间件（对标 kode/config/logging.php access_log 开启态）。
 *
 * 每请求记录 method/uri/status/延迟（真实文件 I/O，复用 worker 级文件句柄）。
 * 注意：kode 侧 access_log 默认异步（响应后批量落盘），本中间件为同步写以体现
 * 「真实日志后端」代价；两者中间件帧 + 响应头写入同构，后端差异已在报告中标注。
 */
class AccessLogMiddleware implements MiddlewareInterface
{
    /** @var resource|null */
    private static $fh;

    public function process(Request $request, callable $handler): Response
    {
        try {
            $t0 = microtime(true);
            $response = $handler($request);
            $us = (int) ((microtime(true) - $t0) * 1_000_000);

            if (self::$fh === null) {
                self::$fh = @fopen(sys_get_temp_dir() . '/webman_access.log', 'a');
            }
            if (self::$fh) {
                fwrite(self::$fh, sprintf(
                    "%s %s %d %d\n",
                    $request->method(),
                    $request->uri(),
                    $response->getStatusCode(),
                    $us
                ));
            }

            return $response;
        } catch (\Throwable $e) {
            file_put_contents('/tmp/mw_err.txt', "AccessLog: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
            throw $e;
        }
    }
}
