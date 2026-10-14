<?php

declare(strict_types=1);

namespace Kode\Framework\Http;

use Kode\Http\Response;

/**
 * 响应构造助手（默认统一信封，可配置关闭）。
 *
 * 信封格式（http.envelope = true，默认）：
 *   - 成功：{code, msg, data} —— code 默认等于 HTTP 状态码，可传自定义业务码。
 *   - 分页：在信封同级附带 total / page / per_page / last_page（业务数据仍在 data 下）。
 *   - 错误：{code, msg, data:null, ...$extra} —— extra（errors 等）并入根级。
 *
 *   return Resp::json($user);                          // {code:200, msg:'ok', data:{...}}
 *   return Resp::json($user, code: 10001);             // 自定义业务码
 *   return Resp::error('参数错误', 400);                // {code:400, msg:'参数错误', data:null}
 *   return Resp::paginate($items, $total, $page, 10);  // 附带 total/page 等分页元数据
 *
 * 直出模式（http.envelope = false，兼容旧行为）：
 *   - 成功：直接返回数据 JSON（无信封）。
 *   - 错误：{"message": "..."} 并带正确 HTTP 状态。
 *
 * 健康探针等需保持极简格式的场景，用 {@see Resp::rawJson()} / {@see Resp::rawError()}
 * 显式绕过信封（EnvelopMiddleware 也会跳过探针路径）。
 */
final class Resp
{
    private static bool $envelope = true;

    /**
     * 由 HttpServiceProvider 启动时按 config('http.envelope') 注入。
     */
    public static function envelope(bool $on): void
    {
        self::$envelope = $on;
    }

    public static function isEnvelopeEnabled(): bool
    {
        return self::$envelope;
    }

    /**
     * 成功响应。
     *
     * @param int|null $code 自定义业务码（默认等于 HTTP 状态码 $status）
     * @param string   $msg  成功提示（默认 'ok'）
     */
    public static function json(mixed $data = null, int $status = 200, ?int $code = null, string $msg = 'ok'): Response
    {
        if (!self::$envelope) {
            $payload = $data === null ? new \stdClass() : $data;
            $body = json_encode($payload, Response::JSON_FLAGS | JSON_THROW_ON_ERROR);
            return Response::make($body, $status);
        }

        $body = json_encode(self::envelopePayload($data, $status, $msg, $code), Response::JSON_FLAGS | JSON_THROW_ON_ERROR);
        return Response::make($body, $status)->header('X-Kode-Envelope', '1');
    }

    /**
     * 分页成功响应：业务数据在 data，分页元数据（total/page/per_page/last_page）与 code/msg 同级。
     *
     * @param list<mixed> $items 当前页数据
     */
    public static function paginate(array $items, int $total, int $page, int $perPage, int $status = 200, ?int $code = null, string $msg = 'ok'): Response
    {
        if (!self::$envelope) {
            // 直出模式：沿用常见 {items, total, per_page, current_page, last_page} 结构。
            $payload = [
                'items'        => $items,
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => (int) ceil($total / max(1, $perPage)),
            ];
            $body = json_encode($payload, Response::JSON_FLAGS | JSON_THROW_ON_ERROR);
            return Response::make($body, $status);
        }

        $payload = self::envelopePayload($items, $status, $msg, $code);
        $payload['total'] = $total;
        $payload['page'] = $page;
        $payload['per_page'] = $perPage;
        $payload['last_page'] = (int) ceil($total / max(1, $perPage));

        $body = json_encode($payload, Response::JSON_FLAGS | JSON_THROW_ON_ERROR);
        return Response::make($body, $status)->header('X-Kode-Envelope', '1');
    }

    /**
     * 错误响应。
     *
     * @param array<string, mixed> $extra 额外字段（errors / field 等），并入根级。
     * @param int|null             $code  自定义业务码（默认等于 HTTP 状态码 $status）
     */
    public static function error(string $message, int $status = 400, array $extra = [], ?int $code = null): Response
    {
        if (!self::$envelope) {
            $body = ['message' => $message];
            if (isset($extra['errors'])) {
                $body['errors'] = $extra['errors'];
                unset($extra['errors']);
            }
            if ($extra !== []) {
                $body = array_merge($body, $extra);
            }
            $encoded = json_encode($body, Response::JSON_FLAGS | JSON_THROW_ON_ERROR);
            return Response::make($encoded, $status);
        }

        $payload = [
            'code' => $code ?? $status,
            'msg'  => $message,
            'data' => null,
        ];
        if ($extra !== []) {
            $payload = array_merge($payload, $extra);
        }

        $encoded = json_encode($payload, Response::JSON_FLAGS | JSON_THROW_ON_ERROR);
        return Response::make($encoded, $status)->header('X-Kode-Envelope', '1');
    }

    /**
     * 直出成功响应：无论信封开关如何，都不套信封、不打信封标记。
     * 供健康探针等需保持极简格式的场景使用（EnvelopMiddleware 跳过打标响应）。
     */
    public static function rawJson(mixed $data = null, int $status = 200): Response
    {
        $payload = $data === null ? new \stdClass() : $data;
        $body = json_encode($payload, Response::JSON_FLAGS | JSON_THROW_ON_ERROR);
        return Response::make($body, $status)->header('X-Kode-Envelope', 'passthrough');
    }

    /**
     * 直出错误响应：无论信封开关如何，都不套信封、不打信封标记。
     */
    public static function rawError(string $message, int $status = 400, array $extra = []): Response
    {
        $body = ['message' => $message];
        if (isset($extra['errors'])) {
            $body['errors'] = $extra['errors'];
            unset($extra['errors']);
        }
        if ($extra !== []) {
            $body = array_merge($body, $extra);
        }
        $encoded = json_encode($body, Response::JSON_FLAGS | JSON_THROW_ON_ERROR);
        return Response::make($encoded, $status)->header('X-Kode-Envelope', 'passthrough');
    }

    /**
     * 自由构造响应（链式调用的入口，不套信封）。
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

        return Response::make((string) $body, $status, $headers)->header('X-Kode-Envelope', 'passthrough');
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

    /**
     * 组装信封负载。
     *
     * @param array<string, mixed> $extra 并入根级的额外字段
     * @return array<string, mixed>
     */
    private static function envelopePayload(mixed $data, int $status, string $msg, ?int $code = null, array $extra = []): array
    {
        $payload = [
            'code' => $code ?? $status,
            'msg'  => $msg,
            'data' => $data,
        ];
        if ($extra !== []) {
            $payload = array_merge($payload, $extra);
        }

        return $payload;
    }
}
