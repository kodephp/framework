<?php

/*
 * webman 全局中间件配置（对标 kode L1 边缘跨切面）。
 *
 * 本最小引导未走 webman 标准 config bootstrap，webman\App 不会自动加载本文件，
 * 故中间件统一由 kode_server.php 在 WEBMAN_MW=on 时显式 Middleware::load 注册
 * （见 kode_server.php 的 middleware 段）。本文件保持返回空数组，避免任何重复注册。
 */

return [];
