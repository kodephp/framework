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

打开浏览器访问 <http://127.0.0.1:9527/health> ，看到 `{"status":"ok","service":"kode-app",...}` 即成功。

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

        return $this->json([
            'hello' => $name,
        ]);
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

返回（**标准 JSON**，默认模式，后面细讲）：

```json
{"hello":"Kode"}
```

---

## 4. 响应：标准模式（默认）与信封模式（可选）

框架默认采用**标准响应**，对齐 Laravel / webman / Hyperf 等主流 PHP 框架——**成功直接返回数据，错误直接带 HTTP 状态**，不再强制统一信封：

```json
{ "hello": "Kode" }              // 成功：直接是数据
{ "message": "参数错误" }         // 失败：标准 message + HTTP 400
```

这跟「其他同类框架」一致：成功就是数据、错误就是 `4xx/5xx` 状态 + 一句 message，没有额外的 `code/msg/data` 包装层。

### 控制器里怎么返回

| 写法 | 效果 |
| --- | --- |
| `return $this->json($data);` | 成功，标准 JSON（**推荐默认写法**） |
| `return $this->error('参数错误', 400);` | 失败，HTTP 400 + `{"message":"参数错误"}` |
| `return ['foo' => 'bar'];` | 直接返回数组 → 自动 JSON 化 |
| `return $this->respond($data, 'msg', 0, 200);` | 跟随配置：envelope=false→标准，true→信封 |
| `return $this->ok($data, '成功');` | 显式信封（code=0），无视配置 |
| `return $this->fail('参数错误', 'E400', 400);` | 显式信封失败，HTTP 400 |
| `return $this->response($data)->status(201);` | 想要自定义状态码/头时用 |

> **为什么改成标准响应？** 主流框架从不强制信封：客户端/前端按 HTTP 状态判断成败，成功体就是业务数据，错误体就是 message。少一层包装，前后端都更省事。如果你的团队已有「统一信封 `{code,msg,data}`」契约（常见于部分内网中文 API），见下方「信封模式」开启即可，两种写法可并存。

### 信封模式（可选，兼容旧契约）

把 `config/response.php` 的 `envelope` 设为 `true`（或环境变量 `RESPONSE_ENVELOPE=true`）：

```json
{ "code": 0, "msg": "ok", "data": { "hello": "Kode" } }   // 成功
{ "code": "E400", "msg": "参数错误" }                       // 失败
```

- `code`：**0 表示成功**；非 0 是业务错误码（如 `"E400"`）。
- `msg`：给人看的一句话提示。
- `data`：业务数据（失败时可省略或带错误明细）。

开启后，`Resp::auto()` / `Controller::respond()` 会自动产出信封；`Resp::ok()/fail()` / `Controller::ok()/fail()` 始终产出信封，与配置无关。

### 异常也会自动转成标准错误

你**不需要**手写 try/catch 来兜底格式。比如参数校验失败：

```php
$this->validate($this->params(), [
    'name' => 'required|min:2|max:50',
]);
```

校验不通过时，框架自动返回（标准模式）：

```json
{"message":"参数校验失败","errors":{ "name": ["name 至少 2 个字符"] }}
```

HTTP 状态为 **422**。路由找不到 → 404、没登录 → 401、限流 → 429、服务器出错 → 500，**全部自动转成标准错误响应**（信封模式下则转成对应 `code` 的信封）。

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

- `GET /health` → `{"status":"ok","service":"kode-app","version":"0.6.0","php":"8.3.x","env":"local","time":"..."}`（K8s/负载均衡探针用）
- `GET /` → 框架元信息

---

## 8. 小白常见问题

| 现象 | 原因 / 解决 |
| --- | --- |
| 访问 502 / 连不上 | 端口被旧进程占用。`lsof -i tcp:9527` 找到进程 `kill` 掉，重启 `serve`。 |
| 改了路由没生效 | 路由在 `app/routes.php`；多进程下重启 `serve` 才加载。 |
| 返回空 / 500 | 看 `storage/logs/app.log`；`APP_DEBUG=true` 时浏览器会显示开发者友好的调试页，API 客户端拿到含栈信息的 JSON。 |
| 报错 "Class not found" | 新加了类？跑 `composer dump-autoload`。 |
| 想看所有路由 | 命令行：`php bin/kode console route:list`（按分组展示数量）。 |

---

## 下一步

- 想做登录鉴权、缓存、数据库、事件、熔断？看 [高级用法](advanced.md)。
- 想看全部内置能力？回仓库根目录读 `README.md`。
