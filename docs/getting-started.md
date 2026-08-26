# 入门指南

本篇目标：照着敲，**10 分钟内跑起你的第一个接口**，并理解「统一响应、收参、校验」三件事。

---

## 1. 环境要求

- PHP **>= 8.3**
- 扩展：`pcntl`、`posix`、`sockets`（多进程服务所需）
- Composer 2.x

```bash
php -v                                   # 版本需 >= 8.3
php -m | grep -E "pcntl|posix|sockets"   # 三个都要有
```

---

## 2. 三步跑起来

框架以 Composer 包发布。**一句话安装**（项目名 `myapp` 写在包名后，可任意命名）：

```bash
composer create-project kode/framework myapp
cd myapp
php bin/kode serve
```

`composer create-project` 会完成三件事：下载框架 → 运行 `composer install` 安装全部依赖 → 自动执行 `kode init` 生成 `.env` 与 `storage/` 目录。打开 <http://127.0.0.1:9527/health> ，看到 `{"status":"ok",...}` 即成功。

> 默认端口 **9527**。换端口：`php bin/kode serve --port 8080`。

> 若把框架作为依赖引入已有项目：`composer require kode/framework`，再把仓库里的 `app/`、`config/`、`bin/`、`lang/`、`database/` 复制到项目根，然后 `php vendor/bin/kode init`。

---

## 3. 目录结构

```
myapp/
├── app/                    ← 应用代码（目录小写约定，类文件首字母大写驼峰）
│   ├── http/
│   │   ├── Controllers/    ← 控制器（app\Http\Controllers）
│   │   └── Middleware/     ← 中间件（app\Http\Middleware）
│   ├── console/            ← 自定义控制台命令（app\Console）
│   ├── services/           ← 业务服务（app\Services）
│   ├── tasks/              ← 定时任务（app\Tasks，#[Cron]）
│   ├── process/            ← 常驻进程（app\Process）
│   ├── events/             ← 事件类（app\Events）
│   ├── aop/                ← AOP 切面（app\Aop）
│   ├── plugins/            ← 插件（app\Plugins）
│   ├── routes.php          ← 显式路由
│   └── bootstrap.php       ← 全局引导（事件监听、第三方初始化）
├── config/                 ← 全部配置（每主题一个文件）
├── database/               ← 迁移与种子
├── docs/                   ← 开发文档
├── lang/                   ← 语言包
├── storage/                ← 缓存 / 日志 / 会话
├── bin/kode                ← CLI 入口
└── .env                    ← 本地环境变量（不入库）
```

> **约定**：`app/` 下目录一律小写（`http`、`console`、`services` 等），类文件首字母大写驼峰（`UserController.php`）；对应命名空间首字母大写（`app\Http\Controllers\UserController`）。新增类文件后执行 `composer dump-autoload`（框架已开启 `optimize-autoloader`）。

---

## 4. 你的第一个接口

### 4.1 写控制器

`app/http/controllers/HelloController.php`：

```php
<?php

declare(strict_types=1);

namespace app\Http\Controllers;

use Kode\Framework\Http\Controller;

final class HelloController extends Controller
{
    public function say(): array
    {
        $name = $this->input('name', '世界');   // 收参，缺省 "世界"
        return ['hello' => $name];              // 直接返回数组 → 自动 JSON 化
    }
}
```

### 4.2 写路由

`app/routes.php`：

```php
use Kode\Http\App;
use app\Http\Controllers\HelloController;

return function (App $app): void {
    $app->get('/hello', fn() => resolve(HelloController::class)->say());
};
```

### 4.3 访问

```bash
curl "http://127.0.0.1:9527/hello?name=Kode"
# {"hello":"Kode"}
```

---

## 5. 响应：标准 JSON（默认）

框架默认采用**标准响应**，对齐 Laravel / webman / Hyperf——**成功直接返回数据，错误直接带 HTTP 状态**：

```json
{ "hello": "Kode" }              // 成功：直接是数据
{ "message": "参数错误" }         // 失败：标准 message + HTTP 400
```

不用再套一层 `{code,msg,data}` 信封，前后端都更省事。

### 控制器里怎么返回

| 写法 | 效果 |
| --- | --- |
| `return ['foo' => 'bar'];` | 直接返回数组 → 自动 JSON 化（最简单） |
| `return $this->json($data);` | 成功，标准 JSON（**推荐写法**） |
| `return $this->error('参数错误', 400);` | 失败，HTTP 400 + `{"message":"参数错误"}` |
| `return $this->response($data)->status(201);` | 想自定义状态码/头时用 |
| `return Resp::json($data);` / `Resp::error($msg, 400);` | 在中间件 / 服务里也能用 |

---

## 6. 接收参数（短方法）

别再写啰嗦的 `$request->getQueryParams()['x']`，控制器自带短方法：

```php
$this->input('name');          // 合并取值：GET + POST + JSON，缺省返回 null
$this->input(['name','page']); // 批量 → 只要这几个字段
$this->query('page');          // 仅 GET 参数（?page=2）
$this->post('payload');        // 仅请求体（含 JSON）
$this->params();               // 全部入参（GET+POST+JSON 合并）
$this->only('name','page');    // 字段筛选
$this->param('id');            // 路由路径参数（/users/{id} 中的 id）
```

需要读 header / 上传文件 / body 流时用 `$this->request()`，它返回完整 PSR-7 请求对象。

---

## 7. 参数校验

```php
public function store(): array
{
    $data = $this->validate($this->params(), [
        'name'  => 'required|min:2|max:50',
        'email' => 'required|email',
    ]);

    // 校验通过才继续；失败自动抛异常 → 转成 422
    return $this->json($data);
}
```

校验不通过时，框架自动返回：

```json
{"message":"参数校验失败","errors":{ "name": ["name 至少 2 个字符"] }}
```

HTTP 状态 **422**。规则用字符串管道写法：`required`、`email`、`min:2`、`max:50`、`int`、`numeric`、`in:a,b` 等，底层是 Symfony Validator。

---

## 8. 异常会自动变成错误响应

你**不需要**手写 try/catch 兜底格式。任何未捕获的异常都会被全局 `ExceptionMiddleware` 接住，转成结构化 JSON：

- 路由找不到 → 404
- 没登录 → 401
- 限流 → 429
- 服务器出错 → 500（带 `location` 与 `chain` 便于定位）

```json
{
  "code": 50000,
  "msg": "用户不存在",
  "type": "RuntimeException",
  "trace_id": "9f2c...",
  "location": { "file": "app/services/UserService.php", "line": 42, "method": "app\\Services\\UserService::find" },
  "chain": [ "app/services/UserService.php:42", "app/http/controllers/UserController.php:17" ]
}
```

- 开发期（`APP_DEBUG=true`）：响应含 `location` 与 `chain`，直接定位源码。
- 生产（`APP_DEBUG=false`，**默认**）：自动收敛绝对路径与系统异常细节，统一返回 `config/http.php` 中的 `production_message`（默认「系统繁忙，请稍后重试」），细节只记日志不泄露。

---

## 9. 健康检查 & 探针

- `GET /health` → `{"status":"ok","service":"kode-app","version":"0.8.51","php":"8.3.x","env":"local","time":"..."}`（K8s / 负载均衡探针用）
- `GET /` → 框架元信息

---

## 10. 常用命令

```bash
php bin/kode serve            # 启动多进程 HTTP 服务
php bin/kode console greet    # 运行自定义命令（app/console 下自动发现）
php bin/kode console route:list   # 查看全部路由
php bin/kode cron             # 启动常驻定时调度器
php bin/kode console process:check  # 查看/校验常驻进程（不 fork）
php bin/kode make:controller User   # 生成控制器
php bin/kode make:command SendNewsletter  # 生成命令（落到 app/console/）
php bin/kode make:model Product      # 生成模型
php bin/kode make:migration create_users  # 生成迁移
```

---

## 11. 常见问题

| 现象 | 原因 / 解决 |
| --- | --- |
| 访问连不上 | 端口被旧进程占用。`lsof -i tcp:9527` 找到进程 `kill` 掉，重启 `serve`。 |
| 改了路由没生效 | 路由在 `app/routes.php`；多进程下重启 `serve` 才加载。 |
| 返回 500 | 看 `storage/logs/app.log`；异常默认返回结构化 JSON（开发期含 `location` 文件/行号与 `chain`），没有 HTML 调试页。 |
| 报错 "Class not found" | 新加了类？跑 `composer dump-autoload`。 |
| 想看所有路由 | `php bin/kode console route:list`（按分组展示数量）。 |
| 命令没被发现 | 命令类必须在 `app/console/` 下、命名空间为 `app\Console\`、类名以 `Command` 结尾。 |

---

## 下一步

- 做登录鉴权、缓存、数据库、事件、熔断、定时任务？看 [文档总览](README.md) 的学习路径。
- 想定制更多能力？持续阅读 [配置与环境变量](config.md) → [路由全解](routing.md) → [DI 与服务提供者](di.md)。