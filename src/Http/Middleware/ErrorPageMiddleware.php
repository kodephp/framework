<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Middleware;

use Kode\Framework\Http\ErrorRenderer;
use Kode\Framework\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * 框架自有错误处理中间件（替换 kode/http 默认的 JsonErrorHandlerMiddleware）。
 *
 * 设计立场：
 *  - 完全掌控错误响应形态：未捕获异常 → 开发期渲染 Whoops 风格调试页 / 生产期标准 JSON；
 *    校验异常（422）→ 结构化错误（含字段明细）。
 *  - 不依赖 kode/http 的 JsonErrorHandlerMiddleware（其 instanceof HttpException 在
 *    部分 kode 生态版本组合下会因类声明冲突而 fatal），错误页 / 标准响应由框架统一产出。
 *  - 对正常响应与显式错误响应（如 404/401/429 由其它中间件返回）一律原样透传，不做二次包装。
 */
final class ErrorPageMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly bool $debug,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $response = $handler->handle($request);
        } catch (ValidationException $e) {
            return ErrorRenderer::render($e, $request, $this->debug, 422, ['errors' => $e->errors()]);
        } catch (\Throwable $e) {
            if ($this->logger !== null) {
                $this->logger->error($e->getMessage(), ['exception' => $e]);
            }

            return ErrorRenderer::render($e, $request, $this->debug, 500);
        }

        return $response;
    }
}
