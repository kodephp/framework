<?php

/*
 * CSRF 防护配置
 *
 * 防线立场：CSRF 是「按需挂载」的企业级中间件——仅被 #[Csrf] 标记的路由（或
 * auto_apply_unsafe 命中的非安全路由）才触发令牌校验；其余路由（含 /ping、
 * 纯 JWT 接口）在全局中间件里 O(1) 早退，零开销，故「加上企业中间件也不影响响应」。
 *
 * 前置依赖：会话（LazySessionMiddleware）。令牌存于会话，无会话（纯 JWT 无 cookie
 * 应用）的路由即便被标记也会安全跳过——CSRF 仅对 cookie-session 形态的会话劫持有效。
 */

return [
    // 是否启用 CSRF 全局中间件（默认开；全局中间件本身对无关路由零开销）。
    'enabled' => (bool) env('CSRF_ENABLED', true),

    // 会话中存储令牌的键名。
    'token_key' => env('CSRF_TOKEN_KEY', '_csrf_token'),

    // 引导令牌回传的响应头（SPA / 表单从此头读取）。
    'header' => env('CSRF_HEADER', 'X-CSRF-Token'),

    // Angular 等框架的 XSRF 双提交 cookie 头（亦被接受为提交令牌来源）。
    'xsrf_header' => env('CSRF_XSRF_HEADER', 'X-XSRF-Token'),

    // 表单 / JSON 体中提交令牌的字段名（亦可经查询参数携带）。
    'token_param' => env('CSRF_TOKEN_PARAM', '_token'),

    // 校验失败响应（419 为 Laravel 惯例，贴合前端拦截器预期）。
    'error_status' => (int) env('CSRF_ERROR_STATUS', 419),
    'error_message' => env('CSRF_ERROR_MESSAGE', 'CSRF token mismatch'),

    // 自动对所有非安全方法（POST/PUT/PATCH/DELETE）路由套用 CSRF（默认关，推荐用 #[Csrf] 精确标记）。
    'auto_apply_unsafe' => (bool) env('CSRF_AUTO_APPLY_UNSAFE', false),

    // auto_apply_unsafe 模式下的排除路径（探针 / 健康检查等无需防护）。
    'exclude_paths' => [
        '/health', '/health/live', '/health/ready', '/ping',
        '/metrics', '/favicon.ico',
    ],

    // 显式跳过 CSRF 校验的路径（即便被 #[Csrf] 标记也豁免，用于需跨站调用的 Webhook 等）。
    'skip_paths' => [],
];
