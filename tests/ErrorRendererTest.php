<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Http\ErrorRenderer;
use Kode\Http\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * ErrorRenderer（开发者友好调试页 / 标准 JSON 错误）单元测试。
 */
final class ErrorRendererTest extends TestCase
{
    private function requestWithAccept(string $accept): ServerRequest
    {
        return new ServerRequest('GET', '/', ['Accept' => $accept]);
    }

    public function testJsonErrorInProductionHidesTrace(): void
    {
        $e = new \RuntimeException('boom');
        $resp = ErrorRenderer::render($e, $this->requestWithAccept('application/json'), false);

        self::assertSame(500, $resp->getStatusCode());
        $body = json_decode((string) $resp->getBody(), true);
        self::assertSame('Internal Server Error', $body['message']);
        self::assertArrayNotHasKey('trace', $body);
        self::assertArrayNotHasKey('exception', $body);
    }

    public function testJsonErrorInDebugExposesTrace(): void
    {
        $e = new \RuntimeException('boom');
        $resp = ErrorRenderer::render($e, $this->requestWithAccept('application/json'), true);

        $body = json_decode((string) $resp->getBody(), true);
        self::assertSame('boom', $body['message']);
        self::assertSame(\RuntimeException::class, $body['exception']);
        self::assertArrayHasKey('trace', $body);
    }

    public function testHtmlDebugPageRenderedForBrowser(): void
    {
        $e = new \RuntimeException('something broke', 0);
        $resp = ErrorRenderer::render($e, $this->requestWithAccept('text/html'), true);

        self::assertSame(500, $resp->getStatusCode());
        self::assertStringContainsString('text/html', $resp->getHeaderLine('Content-Type'));
        $html = (string) $resp->getBody();
        self::assertStringContainsString('RuntimeException', $html);
        self::assertStringContainsString('something broke', $html);
        self::assertStringContainsString('堆栈跟踪', $html);
    }

    public function testHtmlPrefersOverJsonWhenBrowser(): void
    {
        // Accept 同时含 html/json 时，浏览器场景应给 HTML。
        $e = new \RuntimeException('x');
        $resp = ErrorRenderer::render($e, $this->requestWithAccept('text/html,application/json'), true);
        self::assertStringContainsString('text/html', $resp->getHeaderLine('Content-Type'));
    }

    public function testJsonPreferredWhenExplicitJson(): void
    {
        $e = new \RuntimeException('x');
        $resp = ErrorRenderer::render($e, $this->requestWithAccept('application/json'), true);
        self::assertStringContainsString('application/json', $resp->getHeaderLine('Content-Type'));
    }

    public function testRenderMessage404Json(): void
    {
        $resp = ErrorRenderer::renderMessage('Not Found', 404, $this->requestWithAccept('*/*'), false);
        self::assertSame(404, $resp->getStatusCode());
        $body = json_decode((string) $resp->getBody(), true);
        self::assertSame('Not Found', $body['message']);
    }

    public function testRenderMessage404HtmlInDebug(): void
    {
        $resp = ErrorRenderer::renderMessage('Not Found', 404, $this->requestWithAccept('text/html'), true);
        self::assertStringContainsString('text/html', $resp->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Not Found', (string) $resp->getBody());
    }

    public function testSensitiveHeadersMaskedInHtml(): void
    {
        $req = $this->requestWithAccept('text/html')
            ->withHeader('Authorization', 'Bearer secret-token')
            ->withHeader('X-Custom', 'visible');
        $e = new \RuntimeException('leak test');
        $html = (string) ErrorRenderer::render($e, $req, true)->getBody();

        self::assertStringContainsString('***', $html);
        self::assertStringNotContainsString('secret-token', $html);
        self::assertStringContainsString('visible', $html);
    }
}
