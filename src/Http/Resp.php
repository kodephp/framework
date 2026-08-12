<?php

declare(strict_types=1);

namespace Kode\Framework\Http;

use Kode\Http\Response;

/**
 * 响应构造助手（薄封装，仅标准响应）。
 *
 * 框架默认采用标准响应（对齐 Laravel / webman / Hyperf），不套信封：
 *   - 成功：Resp::json($data) / return [...] 直接返回数据 JSON。
 *   - 错误：Resp::error($msg, $status) 返回 {"message": "..."} 并带正确 HTTP 状态。
 *
 * 统一信封 {code, msg, data} 不属于框架职责——开发者如需，自行组装数组返回即可：
 *
 *   return ['code' => 0, 'msg' => 'ok', 'data' => $user];
 *
 * 便捷写法：
 *
 *   return Resp::json($user);
 *   return Resp::error('参数错误', 400);
 */
final class Resp
{
    /**
     * 标准成功响应（直接返回数据 JSON，无信封）。
     *
     * 等价于控制器里 `return $user;` / `return [...];` 的 JSON 化结果。
     */
    public static function json(mixed $data = null, int $status = 200): Response
    {
        return Response::json($data === null ? new \stdClass() : $data)->status($status);
    }

    /**
     * 标准错误响应（直接带 HTTP 状态，无信封）。
     *
     *   return Resp::error('参数错误', 400, ['errors' => [...]]);
     *   // → {"message":"参数错误","errors":[...]}  (status 400)
     *
     * @param array<string, mixed> $extra 额外字段（errors / field 等），并入根级。
     */
    public static function error(string $message, int $status = 400, array $extra = []): Response
    {
        $body = ['message' => $message];
        if (isset($extra['errors'])) {
            $body['errors'] = $extra['errors'];
            unset($extra['errors']);
        }
        if ($extra !== []) {
            $body = array_merge($body, $extra);
        }

        return Response::json($body)->status($status);
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
     *     ->cookie('sid', $sid, httpOnly: true);
     * ```
     *
     * @param array<string, mixed> $headers
     */
    public static function make(mixed $data = null, int $status = 200, array $headers = []): Response
    {
        $body = $data === null ? '' : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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
     * 302 重定向。
     */
    public static function redirect(string $location, int $status = 302): Response
    {
        return Response::make('', $status)->header('Location', $location);
    }
}
