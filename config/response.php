<?php

/*
 * HTTP 响应与错误页配置
 *
 * 设计立场（对齐 Laravel / webman / Hyperf 等主流框架）：
 *   - 框架**默认采用标准响应**：成功直接返回数据、错误直接带 HTTP 状态返回，
 *     不再强制统一的 {code, msg, data} 信封（那种风格更适合部分内网中文 API 契约）。
 *   - 需要「统一信封」的团队，把 envelope 设为 true 即可让 ok()/fail()/auto() 产出信封。
 *   - 开发期（app.debug = true）访问出错时，浏览器会看到 Whoops 风格的友好调试页；
 *     API 客户端（Accept: application/json）仍拿到结构化 JSON（含 trace）。
 */

return [
    /*
     * 是否启用统一信封 {code, msg, data}。
     *
     *   false（默认）：标准模式。
     *     - 成功：Resp::json($data) / return [...]，直接返回数据 JSON。
     *     - 错误：Resp::error($msg, $status)，返回 {"message": "...", ...} 带 HTTP 状态。
     *   true：信封模式。
     *     - 成功：Resp::ok($data) 返回 {code:0, msg, data}。
     *     - 错误：Resp::fail($msg, $code, $status) 返回 {code, msg}。
     *
     * 无论哪种模式，Resp::auto() 都会跟随此开关自动选择；Controller::respond() 同理。
     * 也可用更直白的别名：envelope('user.list', $data, ...)。
     */
    'envelope' => (bool) env('RESPONSE_ENVELOPE', false),

    /*
     * 信封模式下的键名（仅 envelope=true 时生效）。
     */
    'envelope_keys' => [
        'code' => 'code',
        'msg'  => 'msg',
        'data' => 'data',
    ],

    /*
     * 开发期错误页（Whoops 风格）。
     *
     *   仅当 app.debug = true 且请求方为浏览器（Accept 含 text/html）时渲染。
     *   生产环境（debug=false）一律返回极简 JSON / 文本，绝不泄露堆栈。
     */
    'error_page' => [
        'enabled' => (bool) env('ERROR_PAGE', true),
        // 调试页顶部品牌名（纯展示）。
        'brand'   => 'Kode Framework',
    ],

    /*
     * 标准错误响应的默认键名（非信封模式）。
     */
    'error_keys' => [
        'message' => 'message',
        'errors'  => 'errors',
    ],
];
