<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\ApiDoc\OpenApiGenerator;
use Kode\Framework\Http\RouteRegistry;
use Kode\Http\App;
use Kode\Http\Response;

/**
 * API 文档自动化服务提供者。
 *
 *  - 注册 {@see OpenApiGenerator} 单例（门面 ApiDoc / 助手 openapi()）。
 *  - 挂载 /docs/openapi.json：返回生成的 OpenAPI 3.0 spec（JSON）。
 *  - 挂载 /docs：返回 Swagger UI 浏览页（默认经 CDN 引用静态资源）。
 *  - 支持 protect：none / token / local。
 */
final class ApiDocServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(OpenApiGenerator::class, function (): OpenApiGenerator {
            /** @var App $app */
            $app = $this->container->get(App::class);
            /** @var RouteRegistry $registry */
            $registry = $this->container->get(RouteRegistry::class);

            return new OpenApiGenerator($app, $registry, (array) $this->config('apidoc', []));
        });
        $this->container->alias('apidoc', OpenApiGenerator::class);
    }

    public function boot(): void
    {
        if (empty($this->config('apidoc.enabled', true))) {
            return;
        }

        /** @var App $app */
        $app = $this->container->get(App::class);
        /** @var OpenApiGenerator $generator */
        $generator = $this->container->get(OpenApiGenerator::class);

        $jsonPath = (string) ($this->config('apidoc.json_path', '/docs/openapi.json') ?: '/docs/openapi.json');
        $uiPath = (string) ($this->config('apidoc.ui_path', '/docs') ?: '/docs');
        $protect = (string) ($this->config('apidoc.protect', 'none') ?: 'none');
        $token = '';

        if ($protect === 'token') {
            $token = (string) ($this->config('apidoc.token', '') ?? '');
            if ($token === '') {
                $token = bin2hex(random_bytes(16));
                fwrite(STDERR, "[apidoc] /docs 令牌（protect=token）：{$token}\n");
            }
        }

        $app->get($jsonPath, function (\Psr\Http\Message\ServerRequestInterface $request) use ($generator, $protect, $token): Response {
            if (!$this->docAllowed($request, $protect, $token)) {
                return Response::make('Forbidden', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
            }

            return Response::make(
                $generator->toJson(),
                200,
                ['Content-Type' => 'application/json; charset=utf-8']
            );
        });

        $app->get($uiPath, function (\Psr\Http\Message\ServerRequestInterface $request) use ($generator, $protect, $token, $jsonPath): Response {
            if (!$this->docAllowed($request, $protect, $token)) {
                return Response::make('Forbidden', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
            }

            return Response::make(
                $this->renderUi($jsonPath),
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            );
        });
    }

    /**
     * 渲染 Swagger UI 页面（CDN 模式）。
     */
    private function renderUi(string $jsonPath): string
    {
        $specUrl = htmlspecialchars($jsonPath, ENT_QUOTES);
        $title = htmlspecialchars((string) ($this->config('apidoc.title', 'API') ?: 'API'), ENT_QUOTES);

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} - API Docs</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js" charset="UTF-8"></script>
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-standalone-preset.js" charset="UTF-8"></script>
    <script>
        window.onload = function () {
            window.ui = SwaggerUIBundle({
                url: '{$specUrl}',
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
                layout: 'StandaloneLayout'
            });
        };
    </script>
</body>
</html>
HTML;
    }

    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request
     */
    private function docAllowed(\Psr\Http\Message\ServerRequestInterface $request, string $protect, string $token): bool
    {
        if ($protect === 'none') {
            return true;
        }

        if ($protect === 'local') {
            $ip = $this->clientIp($request);

            return $ip === '127.0.0.1' || $ip === '::1' || str_starts_with($ip, '127.');
        }

        $query = $request->getQueryParams();
        $given = $query['token'] ?? '';
        if (is_string($given) && $given !== '' && hash_equals($token, $given)) {
            return true;
        }

        $auth = $request->getHeaderLine('Authorization');
        if ($auth !== '' && str_starts_with($auth, 'Bearer ')) {
            return hash_equals($token, substr($auth, 7));
        }

        return false;
    }

    private function clientIp(\Psr\Http\Message\ServerRequestInterface $request): string
    {
        $fwd = $request->getHeaderLine('X-Forwarded-For');
        if ($fwd !== '') {
            return strtok($fwd, ',');
        }
        $real = $request->getHeaderLine('X-Real-IP');
        if ($real !== '') {
            return $real;
        }
        $server = $request->getServerParams();

        return $server['REMOTE_ADDR'] ?? 'unknown';
    }
}
