<?php

declare(strict_types=1);

namespace Kode\Framework\Http;

use Kode\Http\Request as HttpRequest;
use Kode\Http\Response;
use Kode\Framework\Validation\ValidationException;
use Kode\Framework\Validation\Validator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * HTTP 控制器基类
 *
 * 约定：控制器方法接收 PSR-7 ServerRequestInterface 作为首个参数，
 * 直接 return 数组（自动 JSON 化）或 Kode\Http\Response 实例即可。
 * 路由参数通过 Request::param('id') 获取。
 */
abstract class Controller
{
    /**
     * 便捷校验：失败抛出 ValidationException（由框架转为 422）。
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     * @param array<string, string> $messages
     * @return array<string, mixed>
     */
    protected function validate(array $data, array $rules, array $messages = []): array
    {
        $violations = validator()->validate($data, $rules, $messages);
        if ($violations !== []) {
            throw new ValidationException($violations);
        }

        return $data;
    }

    /**
     * 标准成功响应：直接返回数据 JSON（无信封，框架默认推荐写法）。
     *
     *   return $this->json($user);
     *   return $this->json(['items' => $list]);
     *
     * 等价于在控制器里直接 `return $user;` / `return [...];`。
     * 需要统一信封 {code,msg,data} 时用 {@see ok()}（始终信封）或 {@see respond()}（跟随配置）。
     */
    protected function json(mixed $data = null, int $status = 200): Response
    {
        return Resp::json($data, $status);
    }

    /**
     * 跟随 config('response.envelope') 的响应：
     *   - 默认（false）：标准 JSON（同 json()）。
     *   - 开启（true）：统一信封（同 ok()）。
     */
    protected function respond(mixed $data = null, string $msg = 'ok', int $code = 0, int $status = 200): Response
    {
        return Resp::auto($data, $msg, $code, $status);
    }

    /**
     * 标准错误响应：直接带 HTTP 状态（无信封）。
     *
     *   return $this->error('参数错误', 400);
     */
    protected function error(string $message, int $status = 400, array $extra = []): Response
    {
        return Resp::error($message, $status, $extra);
    }

    /**
     * 成功响应（统一信封 {code, msg, data}，code=0）。
     *
     * 仅在需要信封模式时使用；默认推荐用 {@see json()} / 直接 return 数据。
     */
    protected function ok(mixed $data = null, string $msg = 'ok'): Response
    {
        return Resp::ok($data, $msg);
    }

    /**
     * 失败响应（统一信封 {code, msg}，code 为非 0 错误码，带 HTTP 状态）。
     */
    protected function fail(string $msg, string|int $code = 'E400', int $httpStatus = 400): Response
    {
        return Resp::fail($msg, $code, $httpStatus);
    }

    /**
     * 当前请求（PSR-7 ServerRequestInterface）。
     *
     * 需要完整请求能力（header / uploaded file / body stream 等）时直接用它；
     * 取值优先用下方短方法：input() / query() / post() / params() / only()。
     */
    protected function request(): ServerRequestInterface
    {
        $req = HttpRequest::getRequest();
        if ($req === null) {
            throw new \RuntimeException('No active request in context');
        }

        return $req;
    }

    /**
     * 合并取值：query + body + json（任意来源）。
     *
     *   $this->input('name');            // 单个，缺省返回 null
     *   $this->input(['name', 'page']);  // 批量 → 仅返回这些键（等价 only()）
     *
     * @param string|array<string>|null $key
     * @return mixed
     */
    protected function input(string|array|null $key = null, mixed $default = null): mixed
    {
        if (is_array($key)) {
            return HttpRequest::only(...$key);
        }

        return HttpRequest::input($key, $default);
    }

    /**
     * GET 查询参数。
     *
     *   $this->query('fail');   // ?fail=1
     */
    protected function query(string $key, mixed $default = null): mixed
    {
        return HttpRequest::get($key, $default);
    }

    /**
     * POST / 请求体参数（含 json 解析结果）。
     */
    protected function post(string $key, mixed $default = null): mixed
    {
        return HttpRequest::post($key, $default);
    }

    /**
     * 全部入参（query + body + json 合并），用于一次性取用。
     *
     *   $all = $this->params();
     */
    protected function params(): array
    {
        return HttpRequest::all();
    }

    /**
     * 字段筛选：仅保留给定键。
     *
     *   $this->only('name', 'page');
     */
    protected function only(string ...$keys): array
    {
        return HttpRequest::only(...$keys);
    }

    /**
     * 路由路径参数（{id} 等），来源与 input() 不同——它来自 URL 路由匹配，
     * 不在 query/body/json 里。
     *
     *   $this->param('id');   // /users/{id} 中的 id
     */
    protected function param(string $key, mixed $default = null): mixed
    {
        return HttpRequest::param($key, $default);
    }

    /**
     * 链式响应构造器入口。
     *
     * 返回一个可直接链式调用的 {@see Response}（PSR-7 且借鉴主流框架的流畅风格）：
     *
     * ```php
     * return $this->response(['id' => 1])
     *     ->status(201)
     *     ->header('X-Trace', $id)
     *     ->withCors()
     *     ->cookie('sid', $sid, httpOnly: true);
     * ```
     *
     * @param array<string, mixed> $headers
     */
    protected function response(mixed $data = null, int $status = 200, array $headers = []): Response
    {
        return Resp::make($data, $status, $headers);
    }
}
