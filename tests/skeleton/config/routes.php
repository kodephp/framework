<?php

/*
 * 路由来源配置
 *
 * 框架支持两套并存的路由模型，且「默认即自动发现，无需任何开关」：
 *  1) 属性路由（约定优于配置）：启动时递归扫描控制器目录（见 attributes.controllers），
 *     在控制器类/方法上用 #[Controller]/#[Get] 等声明，自动注册——「多应用自动路由匹配」。
 *     在 app/controllers 下新建任意子文件夹（如 Admin/）即成为一个模块，无需配置开启。
 *  2) 显式路由文件：app/routes.php 手写闭包/控制器路由；此外框架会自动 glob
 *     app/routes/*.php（每个文件即一个来源），新增文件即生效，无需登记。
 *
 * 多应用（零开关，与 ThinkPHP/Webman/Hyperf multi-app 同型：应用目录内扁平结构，不套 http 层）：
 * 在 app/ 下建立目录并放一个 routes.php 即成为一个子应用——
 *   app/{App}/routes.php            子应用路由入口（标签 app:<App>）
 *   app/{App}/routes/*.php          子应用分区路由文件（自动 glob）
 *   app/{App}/controllers/          子应用控制器目录（属性路由自动扫描，新约定）
 *   app/{App}/http/controllers/     子应用控制器目录（旧写法，兼容）
 *   app/{App}/console/              子应用命令（自动发现注册）
 *   app/{App}/middleware/ models/   make:middleware|model --app 生成目标
 * namespace 与目录严格对应（PSR-4 app\ → app/），例如
 *   app/admin/controllers/AdminPanelController.php
 *     → class app\admin\controllers\AdminPanelController
 * 不含 routes.php 的普通目录（app/services、app/models 等）不受影响，
 * 不会被误判为子应用。
 *
 * 插件（plugins/<name>）的 routes.php 与 Controllers 也会在目录存在时自动纳入，
 * 成为独立来源（标签 plugin:<name>），同样不需要在配置里开启。
 *
 * 路由来源仅用于 route:list 的分组展示与可读性；不配置任何开关也能正常工作。
 */

return [
    // 属性路由扫描目录（key=来源标签，value=相对项目根的控制器目录）。递归扫描子目录。
    // 注意：此处只写「相对项目根」的子路径，框架在引导期（app() 尚未就绪时）会用
    // 真实的 path.base 拼接成绝对路径，避免依赖当前工作目录（CWD）。
    // 未声明 'app' 键时框架也会按约定自动发现 app/controllers（旧写法 app/http/controllers 兼容）。
    'attributes' => [
        'controllers' => [
            'app' => 'app/controllers',
        ],
    ],

    // 额外的显式路由文件（key=来源标签，value=文件路径）。
    // 注意：app/routes.php 与 app/routes/*.php 已由框架自动加载，通常无需在此声明。
    'sources' => [
        // 'admin' => base_path('app/routes/admin.php'),
    ],
];
