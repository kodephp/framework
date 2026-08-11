<?php

declare(strict_types=1);

namespace Kode\Framework\Http;

use Kode\Http\Response;

/**
 * 框架统一响应体（信封）。
 *
 * 所有 HTTP 响应默认采用 {code, msg, data} 结构（参考 kode/tools 响应体风格）：
 *   - code : 业务码。0 表示成功；非 0 为字符串错误码（如 'E400'）。
 *   - msg  : 人类可读提示。
 *   - data : 业务数据（失败时可省略）。
 *
 * 控制器统一通过 Controller::ok()/fail() 产出，避免散落各种格式。
 *
 *   return $this->ok($user, '创建成功');
 *   return $this->fail('参数错误', 'E400', 400);
 */
final class Resp
{
    /**
     * 成功响应。
     */
    public static function ok(mixed $data = null, string $msg = 'ok', int $code = 0): Response
    {
        return Response::json([
            'code' => $code,
            'msg'  => $msg,
            'data' => $data,
        ]);
    }

    /**
     * 失败响应（业务失败，带 HTTP 状态）。
     *
     * @param array<string, mixed> $data 附加数据（如校验错误列表），并入 data 字段。
     */
    public static function fail(string $msg, string|int $code = 'E400', int $httpStatus = 400, array $data = []): Response
    {
        $body = [
            'code' => $code,
            'msg'  => $msg,
        ];
        if ($data !== []) {
            $body['data'] = $data;
        }

        return Response::json($body)->status($httpStatus);
    }

    /**
     * 分页响应。
     */
    public static function paginate(array $items, int $total, int $page, int $pageSize, string $msg = 'ok'): Response
    {
        return Response::json([
            'code' => 0,
            'msg'  => $msg,
            'data' => [
                'items' => $items,
                'pagination' => [
                    'total'      => $total,
                    'page'       => $page,
                    'page_size'  => $pageSize,
                    'total_page' => $pageSize > 0 ? (int) ceil($total / $pageSize) : 0,
                ],
            ],
        ]);
    }

    /**
     * 自由构造响应（链式调用的入口）。
     *
     * 返回的 {@see Response} 本身是 PSR-7 且完全链式（借鉴 Laravel/ThinkPHP 风格）：
     *
     * ```php
     * return Resp::make($data)
     *     ->status(201)
     *     ->header('X-Trace', $traceId)
     *     ->withCors()
     *     ->withSecurity()
     *     ->cookie('sid', $sid, httpOnly: true);
     * ```
     *
     * @param array<string, mixed> $headers
     */
    public static function make(mixed $data = null, int $status = 200, array $headers = []): Response
    {
        $body = $data === null ? '' : json_encode([
            'code' => 0,
            'msg'  => 'ok',
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return Response::make((string) $body, $status, $headers);
    }

    /**
     * 204 无内容响应。
     */
    public static function noContent(): Response
    {
        return Response::make('', 204);
    }

    /**
     * 302 重定向（业务层语义；纯前端 SPA 通常仍用 JSON 信封）。
     */
    public static function redirect(string $location, int $status = 302): Response
    {
        return Response::make('', $status)->header('Location', $location);
    }
}
