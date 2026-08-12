<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Middleware;

use Kode\Framework\Http\Resp;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 请求体 JSON 健壮性（坏 JSON 显式 400）
 *
 * kode/http 的 Request::post() 在 body 非合法 JSON 时静默返回空数组，
 * 这会导致下游拿到空数据后往往以 422 或 500 收场，错误信息还指向「字段缺失」
 * 而非真正的「请求体格式错误」，排查成本高。
 *
 * 本中间件在请求入口处主动校验：当 Content-Type 声明为 application/json（或
 * +json 后缀）且 body 非空却 `!json_validate` 时，直接返回 400 + 明确错误，
 * 把「格式错误」这一输入问题在最早、最明确的环节拦截下来。
 *
 * 设计立场：
 *  - **默认关闭**，需 config('http.json_strict') = true 才生效（避免影响以
 *    表单/原始文本为 body 的既有接口）。
 *  - 仅对显式声明 JSON 的 Content-Type 生效；表单、纯文本、空 body 一律放行，
 *    不干扰其它合法用法。
 *  - GET/HEAD/OPTIONS 等无 body 语义的方法天然 body 为空，无条件放行。
 */
final class JsonBodyMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $skipPaths 跳过校验的路径前缀（探针等）
     */
    public function __construct(
        private readonly bool $enabled = false,
        private readonly array $skipPaths = ['/health', '/metrics', '/ping'],
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->enabled || !$this->claimsJson($request)) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        foreach ($this->skipPaths as $skip) {
            if (str_starts_with($path, (string) $skip)) {
                return $handler->handle($request);
            }
        }

        $body = (string) $request->getBody();
        if ($body !== '' && !json_validate($body)) {
            return Resp::error('请求体不是合法的 JSON', 400, [
                'error' => 'invalid_json',
            ]);
        }

        return $handler->handle($request);
    }

    private function claimsJson(ServerRequestInterface $request): bool
    {
        $ct = $request->getHeaderLine('Content-Type');
        if ($ct === '') {
            return false;
        }

        // 取分隔符前的主类型，忽略 charset 等参数。允许 application/json 与 application/problem+json 等。
        $main = strtolower(explode(';', $ct)[0]);
        if ($main === 'application/json') {
            return true;
        }

        return str_ends_with($main, '+json');
    }
}
