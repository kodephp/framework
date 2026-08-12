<?php

/*
 * HTTP 内核配置（请求处理与输入健壮性）
 *
 * 这里只放框架 HTTP 层自身的开关，路由/CORS/安全头/限流等另有独立配置文件。
 */

return [
    /*
     * 请求体 JSON 严格校验。
     * 开启后，Content-Type 为 application/json（或 +json）且 body 非空的请求，
     * 若 body 不是合法 JSON，直接返回 400（而非静默当作空对象、下游以 422/500 收场）。
     * 默认关闭，避免影响以表单/纯文本为 body 的既有接口。
     */
    'json_strict' => (bool) env('HTTP_JSON_STRICT', false),

    // 跳过 JSON 严格校验的路径前缀（探针、指标等无需 body 校验）。
    'json_skip_paths' => ['/health', '/metrics', '/ping'],
];
