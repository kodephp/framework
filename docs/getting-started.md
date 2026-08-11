# 入门指南（小白 / 初级）

本篇目标：照着敲，**10 分钟内跑起你的第一个接口**，并理解「统一响应、收参、校验」三件事。

---

## 1. 环境要求

- PHP **>= 8.3**
- 扩展：`pcntl`、`posix`、`sockets`（多进程服务所需）
- Composer 2.x

检查：

```bash
php -v          # 看版本是否 >= 8.3
php -m | grep -E "pcntl|posix|sockets"   # 三个都要有
```

---

## 2. 三步跑起来

### 方式 A：一键脚手架（推荐）

```bash
# 在任意目录执行（框架仓库内的 bin/kode）
php /path/to/kode-framework/bin/kode new myapp --install

cd myapp
php bin/kode serve
```

打开浏览器访问 <http://127.0.0.1:9527/health> ，看到 `{"code":0,"msg":"healthy",...}` 即成功。

> 默认端口 **9527**（致敬星爷《唐伯虎点秋香》）。想换端口：`php bin/kode serve --port 8080`。

### 方式 B：作为 Composer 包引入已有项目

```bash
composer require kode/framework
```

然后复制框架仓库里的 `app/`、`config/`、`bin/`、`lang/` 到你的项目根目录即可。

---

## 3. 你的第一个接口

框架里你日常只改两个地方：

```
app/
├── routes.php              ← 路由（URL 映射到处理器）
└── Http/Controllers/       ← 控制器（写业务逻辑）
```

### 3.1 写控制器

新建 `app/Http/Controllers/HelloController.php`：

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Kode\Framework\Http\Controller;

class HelloController extends Controller
{
    public function say(): array
    {
        $name = $this->input('name', '世界');   // 收参，缺省 "世界"

        return $this->ok([
            'hello' => $name,
        ], '打招呼成功');
    }
}
```

### 3.2 写路由

在 `app/routes.php` 里加一行：

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
```

返回（**统一信封**，后面细讲）：

```json
{"code":0,"msg":"打招呼成功","data":{"hello":"Kode"}}
```

---

## 4. 统一响应（重点）

框架**默认所有 HTTP 响应**都长这样（信封结构）：

```json
{ "code": 0, "msg": "ok", "data": { } }
```

- `code`：**0 表示成功**；非 0 是业务错误码（如 `"E400"`）。
- `msg`：给人看的一句话提示。
- `data`：业务数据（失败时可省略或带错误明细）。

### 控制器里怎么返回

| 写法 | 效果 |
| --- | --- |
| `return $this->ok($data, '成功');` | 成功，code=0 |
| `return $this->fail('参数错误', 'E400', 400);` | 失败，HTTP 400 |
| `return ['foo' => 'bar'];` | 直接返回数组 → 自动包成信封 |
| `return $this->response($data)->status(201);` | 想要自定义状态码/头时用 |

> **为什么框架帮你封装好？** 而不是让你自己拼格式：因为前端/客户端拿到的是**永远一致的结构**，不必每个接口写一遍 `json_encode(["code"=>...])`。你专注业务，格式交给框架。

### 异常也会自动变成信封

你**不需要**手写 try/catch 来兜底格式。比如参数校验失败：

```php
$this->validate($this->params(), [
    'name' => 'required|min:2|max:50',
]);
```

校验不通过时，框架自动返回：

```json
{"code":"E422","msg":"参数校验失败","data":{"errors":{ "name": ["name 至少 2 个字符"] }}}
```

HTTP 状态为 **422**。同样，路由找不到 → `E404`、没登录 → `E401`、限流 → `E429`、服务器出错 → `E500`，**全部自动转成统一信封**。

---

## 5. 接收参数（短方法）

别再用啰嗦的 `getQueryParams()['x']` 了，控制器自带短方法：

```php
$this->input('name');          // 合并取值：GET + POST + JSON，缺省返回 null
$this->input(['name','page']); // 批量 → 只要这几个字段
$this->query('page');          // 仅 GET 参数（?page=2）
$this->post('payload');        // 仅请求体（含 JSON）
$this->params();               // 全部入参（GET+POST+JSON 合并）
$this->only('name','page');    // 字段筛选
```

需要完整请求（读 header / 上传文件 / body 流）时用 `$this->request()`，它返回 PSR-7 请求对象。

---

## 6. 参数校验

```php
public function store(): array
{
    $data = $this->validate($this->params(), [
        'name'  => 'required|min:2|max:50',
        'email' => 'required|email',
    ]);

    // 校验通过才往下走；失败会自动抛异常 → 转成 422 信封
    return $this->ok($data, '创建成功');
}
```

校验规则用字符串管道写法：`required`、`email`、`min:2`、`max:50`、`int`、`numeric`、`in:a,b` 等，底层是 Symfony Validator。

---

## 7. 健康检查 & 探针

框架已内置：

- `GET /health` → `{"code":0,"msg":"healthy","data":{"status":"ok",...}}`（K8s/负载均衡探针用）
- `GET /` → 框架元信息

---

## 8. 小白常见问题

| 现象 | 原因 / 解决 |
| --- | --- |
| 访问 502 / 连不上 | 端口被旧进程占用。`lsof -i tcp:9527` 找到进程 `kill` 掉，重启 `serve`。 |
| 改了路由没生效 | 路由在 `app/routes.php`；多进程下重启 `serve` 才加载。 |
| 返回空 / 500 | 看 `storage/logs/app.log`；开启 `APP_DEBUG=true` 时错误会带栈信息。 |
| 报错 "Class not found" | 新加了类？跑 `composer dump-autoload`。 |
| 想看所有路由 | 命令行：`php bin/kode console route:list`（按分组展示数量）。 |

---

## 下一步

- 想做登录鉴权、缓存、数据库、事件、熔断？看 [高级用法](advanced.md)。
- 想看全部内置能力？回仓库根目录读 `README.md`。
