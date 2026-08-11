<?php

declare(strict_types=1);

namespace Kode\Framework\Http;

use Kode\Framework\Application;
use Kode\Http\Response;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 开发者友好的错误渲染器（Whoops / Flintstone 风格，零外部依赖）。
 *
 * 行为：
 *  - 生产环境（debug=false）：一律返回极简标准 JSON（含 message 与可选 errors），绝不泄露堆栈。
 *  - 开发环境（debug=true）：
 *      · 浏览器（Accept 含 text/html）→ 渲染友好的 HTML 调试页（异常类型、消息、文件行号、
 *        带源码上下文的堆栈、请求与环境信息）。
 *      · API 客户端（Accept: application/json 或任意类型）→ 返回结构化 JSON（含 exception/file/line/trace）。
 *
 * 内容协商依据请求的 Accept 头；cookie / Authorization 等敏感头会被脱敏。
 */
final class ErrorRenderer
{
    private const CONTEXT_LINES = 7;

    /**
     * 调试页品牌名（可用 ErrorRenderer::$brand = '...' 覆盖）。
     */
    public static string $brand = 'Kode Framework';

    /**
     * 渲染一个抛出的异常。
     */
    public static function render(
        \Throwable $e,
        ?ServerRequestInterface $request,
        bool $debug,
        int $status = 500,
        array $extra = []
    ): Response {
        if ($debug && self::wantsHtml($request)) {
            return Response::html(self::debugHtml(
                (string) ($status ?: 500),
                $e->getMessage(),
                get_class($e),
                $e->getFile(),
                $e->getLine(),
                $e->getTrace(),
                $request,
                $extra
            ))->status(self::httpStatus($e, $status));
        }

        return Response::json(self::jsonBody($status, $e->getMessage(), $extra, $e, $debug))
            ->status(self::httpStatus($e, $status));
    }

    /**
     * 渲染一个「无异常对象」的错误（如 404 / 401），message 直出。
     */
    public static function renderMessage(
        string $message,
        int $status,
        ?ServerRequestInterface $request,
        bool $debug,
        array $extra = []
    ): Response {
        if ($debug && self::wantsHtml($request)) {
            return Response::html(self::debugHtml(
                (string) $status,
                $message,
                self::statusTitle($status),
                '',
                0,
                [],
                $request,
                $extra
            ))->status($status);
        }

        return Response::json(self::jsonBody($status, $message, $extra, null, $debug))
            ->status($status);
    }

    /**
     * 标准 JSON 错误体（非信封模式）。
     *
     * @return array<string, mixed>
     */
    private static function jsonBody(int $status, string $message, array $extra, ?\Throwable $e, bool $debug): array
    {
        $body = ['message' => $debug ? $message : self::safeMessage($status, $message)];

        if (isset($extra['errors'])) {
            $body['errors'] = $extra['errors'];
            unset($extra['errors']);
        }
        if ($extra !== []) {
            $body = array_merge($body, $extra);
        }

        if ($debug && $e !== null) {
            $body['exception'] = get_class($e);
            $body['file']      = $e->getFile();
            $body['line']      = $e->getLine();
            $body['trace']     = $e->getTraceAsString();
        }

        return $body;
    }

    /**
     * 生产环境对外暴露的消息（避免泄露内部细节）。
     */
    private static function safeMessage(int $status, string $message): string
    {
        return match ($status) {
            404 => 'Not Found',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            405 => 'Method Not Allowed',
            422 => 'Validation Failed',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            default => $message ?: 'Error',
        };
    }

    private static function httpStatus(\Throwable $e, int $status): int
    {
        if ($status >= 400) {
            return $status;
        }
        if ($e instanceof \Kode\Http\Exception\HttpException) {
            return $e->getHttpStatusCode();
        }

        return 500;
    }

    /**
     * 浏览器是否想要 HTML（按 Accept 头判断）。
     */
    private static function wantsHtml(?ServerRequestInterface $request): bool
    {
        if ($request === null) {
            return false;
        }
        $accept = strtolower($request->getHeaderLine('Accept'));
        if ($accept === '') {
            return false;
        }

        // 浏览器（含 text/html）→ 调试页；API 客户端（仅 application/json / */*）→ JSON。
        return str_contains($accept, 'text/html');
    }

    /**
     * 生成 Whoops 风格的 HTML 调试页。
     *
     * @param array<int, array<string, mixed>> $trace
     * @param array<string, mixed> $extra
     */
    private static function debugHtml(
        string $status,
        string $message,
        string $type,
        string $file,
        int $line,
        array $trace,
        ?ServerRequestInterface $request,
        array $extra
    ): string {
        // 不依赖全局 config()（可能在 app 启动前/测试中调用），用静态品牌默认值。
        $brand = self::$brand;
        $escStatus = self::e($status);
        $escType = self::e($type);
        $escMessage = self::e($message);
        $location = $file !== '' ? self::e($file . ':' . $line) : '—';

        $stack = self::renderStack($trace);
        [$reqHtml, $envHtml] = self::renderSidebars($request);

        $extraHtml = '';
        if (isset($extra['errors']) && is_array($extra['errors'])) {
            $rows = '';
            foreach ($extra['errors'] as $err) {
                $field = self::e((string) ($err['field'] ?? ''));
                $msg = self::e((string) ($err['message'] ?? ''));
                $rows .= "<tr><td class=\"kf-err-field\">{$field}</td><td>{$msg}</td></tr>";
            }
            if ($rows !== '') {
                $extraHtml = "<div class=\"kf-card\"><h3>校验错误</h3><table class=\"kf-tbl\">{$rows}</table></div>";
            }
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$escStatus} · {$escType}</title>
<style>
  :root{--bg:#0b1020;--panel:#121a30;--ink:#e6edf6;--muted:#8aa0c0;--accent:#5b8cff;--err:#ff6b6b;--line:#22304f;}
  *{box-sizing:border-box}
  body{margin:0;font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"PingFang SC","Microsoft YaHei",sans-serif;background:var(--bg);color:var(--ink)}
  .kf-top{display:flex;align-items:center;gap:14px;padding:20px 28px;background:linear-gradient(90deg,#16203c,#0b1020);border-bottom:1px solid var(--line)}
  .kf-badge{background:var(--err);color:#1a0000;font-weight:700;padding:4px 12px;border-radius:8px;font-size:18px}
  .kf-brand{color:var(--muted);font-weight:600;letter-spacing:.5px}
  .kf-wrap{max-width:1100px;margin:0 auto;padding:24px 28px 64px}
  .kf-type{color:var(--err);font-size:20px;font-weight:700;margin:6px 0}
  .kf-msg{font-size:16px;color:var(--ink);margin:0 0 18px;word-break:break-word}
  .kf-loc{color:var(--muted);font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;margin-bottom:20px}
  .kf-grid{display:grid;grid-template-columns:1fr 320px;gap:20px}
  @media(max-width:860px){.kf-grid{grid-template-columns:1fr}}
  .kf-card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:16px 18px;margin-bottom:18px}
  .kf-card h3{margin:0 0 10px;font-size:13px;text-transform:uppercase;letter-spacing:.8px;color:var(--accent)}
  .kf-trace{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px}
  .kf-frame{border:1px solid var(--line);border-radius:10px;margin-bottom:12px;overflow:hidden}
  .kf-frame-head{background:#0e1730;padding:8px 12px;color:var(--muted);cursor:default}
  .kf-frame-head b{color:var(--ink)}
  .kf-code{margin:0;padding:10px 0;background:#0a0f1f;overflow-x:auto}
  .kf-code .ln{display:block;white-space:pre;padding:0 14px;color:#6f86ad}
  .kf-code .ln .no{display:inline-block;width:38px;color:#3c4f72;text-align:right;margin-right:14px;user-select:none}
  .kf-code .hit{background:rgba(255,107,107,.14);color:#ffd9d9}
  .kf-code .hit .no{color:var(--err)}
  .kf-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
  .kf-tbl td,.kf-tbl th{padding:5px 8px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}
  .kf-tbl .kf-err-field{color:var(--accent);font-family:ui-monospace,monospace;white-space:nowrap}
  .kf-kv{font-size:12.5px}
  .kf-kv div{margin:3px 0}
  .kf-kv .k{color:var(--muted)}
  .kf-kv .v{color:var(--ink);word-break:break-all}
  .kf-note{color:var(--muted);font-size:12px;margin-top:8px}
</style>
</head>
<body>
  <div class="kf-top">
    <span class="kf-badge">{$escStatus}</span>
    <span class="kf-brand">{$brand}</span>
  </div>
  <div class="kf-wrap">
    <div class="kf-type">{$escType}</div>
    <p class="kf-msg">{$escMessage}</p>
    <div class="kf-loc">📍 {$location}</div>
    <div class="kf-grid">
      <div>
        <div class="kf-card">
          <h3>堆栈跟踪（Stack Trace）</h3>
          <div class="kf-trace">{$stack}</div>
        </div>
        {$extraHtml}
      </div>
      <div>
        <div class="kf-card">
          <h3>请求（Request）</h3>
          {$reqHtml}
        </div>
        <div class="kf-card">
          <h3>环境（Environment）</h3>
          {$envHtml}
        </div>
      </div>
    </div>
    <p class="kf-note">此调试页仅在 app.debug = true 时显示，生产环境（debug=false）将返回极简 JSON，不会泄露堆栈。</p>
  </div>
</body>
</html>
HTML;
    }

    /**
     * 渲染堆栈（带源码上下文）。
     *
     * @param array<int, array<string, mixed>> $trace
     */
    private static function renderStack(array $trace): string
    {
        if ($trace === []) {
            return '<div class="kf-kv"><div><span class="k">无堆栈信息</span></div></div>';
        }

        $html = '';
        $max = min(12, count($trace));
        for ($i = 0; $i < $max; $i++) {
            $frame = $trace[$i];
            $file = (string) ($frame['file'] ?? '');
            $line = (int) ($frame['line'] ?? 0);
            $class = (string) ($frame['class'] ?? '');
            $func = (string) ($frame['function'] ?? '');
            $call = ($class !== '' ? $class . $func . '()' : $func . '()');
            $where = $file !== '' ? self::e($file . ':' . $line) : 'internal';

            $code = self::codeContext($file, $line);
            $html .= "<div class=\"kf-frame\"><div class=\"kf-frame-head\">#{$i} <b>{$call}</b> &nbsp;<span>{$where}</span></div>{$code}</div>";
        }

        return $html;
    }

    /**
     * 读取源码上下文（命中行高亮）。
     */
    private static function codeContext(string $file, int $line): string
    {
        if ($file === '' || !is_readable($file)) {
            return '';
        }
        try {
            $lines = @file($file, FILE_IGNORE_NEW_LINES);
        } catch (\Throwable) {
            return '';
        }
        if ($lines === false || $line < 1 || $line > count($lines)) {
            return '';
        }

        $start = max(1, $line - self::CONTEXT_LINES);
        $end = min(count($lines), $line + self::CONTEXT_LINES);
        $out = '<pre class="kf-code">';
        for ($n = $start; $n <= $end; $n++) {
            $cls = $n === $line ? ' class="hit"' : '';
            $out .= "<span class=" . '"ln"' . $cls . '><span class="no">' . $n . '</span>'
                . self::e($lines[$n - 1]) . "</span>";
        }

        return $out . '</pre>';
    }

    /**
     * 渲染请求与环境侧栏。
     *
     * @return array{0: string, 1: string}
     */
    private static function renderSidebars(?ServerRequestInterface $request): array
    {
        if ($request === null) {
            return ['<div class="kf-kv"><div><span class="k">（无请求上下文）</span></div></div>', self::envHtml()];
        }

        $method = self::e($request->getMethod());
        $uri = self::e((string) $request->getUri());
        $ip = self::e(self::clientIp($request));
        $query = self::e(json_encode($request->getQueryParams(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
        $headers = self::headersHtml($request);

        $req = <<<HTML
<div class="kf-kv">
  <div><span class="k">方法</span> <span class="v">{$method}</span></div>
  <div><span class="k">URL</span> <span class="v">{$uri}</span></div>
  <div><span class="k">客户端 IP</span> <span class="v">{$ip}</span></div>
  <div><span class="k">Query</span> <span class="v">{$query}</span></div>
</div>
<div class="kf-kv" style="margin-top:10px">{$headers}</div>
HTML;

        return [$req, self::envHtml()];
    }

    private static function envHtml(): string
    {
        $version = Application::VERSION;
        $os = self::e(PHP_OS . ' ' . php_uname('m'));
        $mem = self::e(round(memory_get_peak_usage(true) / 1024 / 1024, 1) . ' MB');
        $time = self::e(date('Y-m-d H:i:s'));
        $sapi = self::e(PHP_SAPI);

        return <<<HTML
<div class="kf-kv">
  <div><span class="k">PHP</span> <span class="v">{PHP_VERSION}</span></div>
  <div><span class="k">框架</span> <span class="v">Kode {$version}</span></div>
  <div><span class="k">SAPI</span> <span class="v">{$sapi}</span></div>
  <div><span class="k">OS</span> <span class="v">{$os}</span></div>
  <div><span class="k">内存峰值</span> <span class="v">{$mem}</span></div>
  <div><span class="k">时间</span> <span class="v">{$time}</span></div>
</div>
HTML;
    }

    private static function headersHtml(ServerRequestInterface $request): string
    {
        $sensitive = ['authorization', 'cookie', 'x-csrf-token', 'x-xsrf-token', 'proxy-authorization'];
        $rows = '';
        foreach ($request->getHeaders() as $name => $values) {
            $key = strtolower($name);
            $val = in_array($key, $sensitive, true) ? '***' : implode(', ', $values);
            $rows .= '<div><span class="k">' . self::e($name) . '</span> <span class="v">' . self::e($val) . '</span></div>';
        }

        return $rows === '' ? '<div><span class="k">（无头）</span></div>' : $rows;
    }

    private static function clientIp(ServerRequestInterface $request): string
    {
        $params = $request->getServerParams();

        return $params['REMOTE_ADDR'] ?? 'unknown';
    }

    private static function statusTitle(int $status): string
    {
        return match ($status) {
            404 => 'NotFoundHttpException',
            401 => 'UnauthorizedHttpException',
            403 => 'ForbiddenHttpException',
            405 => 'MethodNotAllowedHttpException',
            422 => 'ValidationException',
            429 => 'TooManyRequestsHttpException',
            default => 'HttpException',
        };
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
