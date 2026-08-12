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

## 3. 你的第一个接口

日常只改两个地方：

```
app/
├── routes.php               ← 路由（URL 映射到处理器）
└── Http/Controllers/        ← 控制器（写业务逻辑）
```

### 3.1 写控制器

`app/Http/Controllers/HelloController.php`：

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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

### 3.2 写路由

`app/routes.php`：

```php
use Kode\Http\App;
use App\Http\Controllers\HelloController;

return function (App $app): void {
    $app->get('/hello', fn() => resolve(HelloController::class)->say());
};
```

### 3.3 访问

```bash
curl "http://127.0.0.1:9527/hello?name=Kode"
# {"hello":"Kode"}
```

---

## 4. 响应：标准 JSON（默认）

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

## 5. 接收参数（短方法）

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

## 6. 参数校验

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

## 7. 异常会自动变成错误响应

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
  "location": { "file": "app/Services/UserService.php", "line": 42, "method": "App\\Services\\UserService::find" },
  "chain": [ "app/Services/UserService.php:42", "app/Http/Controllers/UserController.php:17" ]
}
```

开发期（`APP_DEBUG=true`）响应含 `location` 与 `chain`；生产环境自动收敛绝对路径与系统细节，只记日志不泄露。

---

## 8. 健康检查 & 探针

- `GET /health` → `{"status":"ok","service":"kode-app","version":"0.7.2","php":"8.3.x","env":"local","time":"..."}`（K8s / 负载均衡探针用）
- `GET /` → 框架元信息

---

## 9. 常见问题

| 现象 | 原因 / 解决 |
| --- | --- |
| 访问连不上 | 端口被旧进程占用。`lsof -i tcp:9527` 找到进程 `kill` 掉，重启 `serve`。 |
| 改了路由没生效 | 路由在 `app/routes.php`；多进程下重启 `serve` 才加载。 |
| 返回 500 | 看 `storage/logs/app.log`；异常默认返回结构化 JSON（开发期含 `location` 文件/行号与 `chain`），没有 HTML 调试页。 |
| 报错 "Class not found" | 新加了类？跑 `composer dump-autoload`。 |
| 想看所有路由 | `php bin/kode console route:list`（按分组展示数量）。 |

---

## 下一步

- 做登录鉴权、缓存、数据库、事件、熔断、定时任务？看 [进阶用法](advanced.md)。
- 想看全部内置能力？回仓库根目录读 `README.md`。
