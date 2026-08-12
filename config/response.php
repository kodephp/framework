<?php

/*
 * HTTP 响应与错误处理配置
 *
 * 框架采用标准响应（对齐 Laravel / webman / Hyperf）：成功直接返回数据 JSON，
 * 错误直接带 HTTP 状态返回，不套 {code,msg,data} 信封。信封由开发者自行组装。
 *
 * 错误处理：
 *   - 统一交由 kode/exception 的 ExceptionManager + UnifiedResponseFormatter 完成；
 *   - 默认即结构化 JSON，开发环境（app.debug=true）含 file / line / chain，
 *     可直接追踪到出错的源文件与行号；生产环境（app.debug=false）自动收敛
 *     绝对路径与系统异常细节（统一 message）。
 *   - 框架不提供「开发者友好 HTML 调试页」——本就是 API 框架。
 *
 * 见 src/Providers/ExceptionServiceProvider.php 与 src/Http/Middleware/ExceptionMiddleware.php。
 */

return [
    /*
     * 标准错误响应的默认键名（非信封模式，由 Resp::error 使用）。
     */
    'error_keys' => [
        'message' => 'message',
        'errors'  => 'errors',
    ],

    /*
     * kode/exception 生产模式收敛后的对外提示语（production 下系统异常不泄露内部细节）。
     * 如需自定义覆盖，可在 config 中设置后由 ExceptionServiceProvider 注入格式化器。
     */
    'production_message' => '系统繁忙，请稍后重试',
];
