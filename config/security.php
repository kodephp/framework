<?php

/*
 * 安全响应头配置
 *
 * 默认对所有响应追加工业级安全头（防嗅探、防点击劫持、Referrer 策略、
 * HSTS）。这些头对纯 API 服务也基本无害；如不需要可整体关闭。
 */

return [
    // 是否启用安全响应头
    'enabled' => (bool) env('SECURITY_HEADERS_ENABLED', true),

    // X-Content-Type-Options: nosniff
    'nosniff' => true,

    // X-Frame-Options（防点击劫持）：DENY / SAMEORIGIN
    'frame_options' => env('SECURITY_FRAME_OPTIONS', 'DENY'),

    // Referrer-Policy
    'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),

    // HSTS（仅 HTTPS 生效；HTTP 下浏览器会忽略，但建议统一下发以便前置 TLS 终止后生效）
    'hsts' => env('SECURITY_HSTS', 'max-age=31536000; includeSubDomains'),

    // X-XSS-Protection（老旧浏览器兼容）
    'xss_protection' => '0',

    // 是否下发 X-Request-Id（链路追踪），建议开启
    'request_id' => (bool) env('SECURITY_REQUEST_ID', true),

    // X-Request-Id 是否允许客户端用同名请求头覆盖（便于跨服务透传）
    'request_id_allow_client' => (bool) env('SECURITY_REQUEST_ID_ALLOW_CLIENT', true),
];
