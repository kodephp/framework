<?php

declare(strict_types=1);

namespace Kode\Framework\Http;

use Kode\Http\Response;

/**
 * 响应构造助手。
 *
 * 框架**默认采用标准响应**（对齐 Laravel / webman / Hyperf）：
 *   - 成功：Resp::json($data) / return [...] 直接返回数据 JSON，不带信封。
 *   - 错误：Resp::error($msg, $status) 返回 {"message": "...", ...} 并带正确 HTTP 状态。
 *
 * 需要「统一信封 {code, msg, data}」的团队（部分内网中文 API 契约）：
 *   - 把 config('response.envelope') 设为 true，Resp::auto()/Controller::respond() 自动走信封；
 *   - 或显式调用 Resp::ok()/fail()（始终返回信封，与配置无关）。
 *
 *   // 标准模式（默认）
 *   return Resp::json($user);
 *   return Resp::error('参数错误', 400);
 *
 *   // 信封模式（可选）
 *   return Resp::ok($user, '创建成功');
 *   return Resp::fail('参数错误', 'E400', 400);
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
     * 失败响应（业务失败，带 HTTP 状态）。信封模式见 config('response.envelope')。
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
     * 标准成功响应（直接返回数据 JSON，无信封）。
     *
     * 这是框架默认推荐写法，与主流 PHP 框架一致：
     *
     *   return Resp::json($user);
     *   return Resp::json(['items' => $list], 200);
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
     *   return Resp::error('参数错误', 400, ['field' => 'email']);
     *   // → {"message":"参数错误","field":"email"}  (status 400)
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
     * 跟随 config('response.envelope') 自动选择响应形态。
     *
     *   - envelope=false（默认）：返回标准 JSON（Resp::json）。
     *   - envelope=true：返回统一信封（Resp::ok）。
     *
     * 控制器里对应的便捷方法是 Controller::respond()。
     */
    public static function auto(mixed $data = null, string $msg = 'ok', int $code = 0, int $status = 200): Response
    {
        if (!empty(config('response.envelope', false))) {
            return self::ok($data, $msg, $code);
        }

        return self::json($data, $status);
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
