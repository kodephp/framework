<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Middleware;

use Kode\Framework\Http\Resp;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * API 版本化中间件（多版本共存 + 强制升级）
 *
 *  - 识别路径首段 `/vN` 为版本，写入请求属性 `api_version`，便于审计 / 日志标注。
 *  - 当 `prefix_required` 开启且路径无版本前缀时，返回 400 提示客户端补全版本。
 *  - 当版本前缀不在 `supported_versions` 时，返回 404（禁用旧版本，平滑下线）。
 *
 * 路由约定：版本化接口统一放到 app/routes/api.php，并用 `$app->group('v1', ...)` 声明，
 * 使路径自然带 `/v1` 前缀。
 */
final class VersioningMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config = [],
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        // 基础设施端点不参与版本校验。
        if ($this->isInfra($path)) {
            return $handler->handle($request);
        }

        if (preg_match('#^/v\d+(?=/|$)#', $path, $m) === 1) {
            $version = substr($m[0], 1); // 去掉前导 '/'
            $supported = (array) ($this->config['supported_versions'] ?? []);
            if ($supported !== [] && !in_array($version, $supported, true)) {
                return Resp::error("不支持的 API 版本：{$version}", 404);
            }

            $request = $request->withAttribute('api_version', $version);

            return $handler->handle($request);
        }

        // 无版本前缀。
        if (!empty($this->config['prefix_required'])) {
            return Resp::error('API 版本前缀缺失，请使用 /vN 前缀（如 /v1）', 400);
        }

        return $handler->handle($request);
    }

    private function isInfra(string $path): bool
    {
        foreach (['/health', '/metrics', '/docs', '/ping'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
