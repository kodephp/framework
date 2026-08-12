# API 文档自动化（OpenAPI + Swagger UI）

框架本地薄实现：扫描已注册路由，自动生成 OpenAPI 3.0 spec，并提供 Swagger UI 浏览页。可选 `#[OpenApi]` 属性补充语义信息。

## 一、端点

| 路径 | 说明 |
| --- | --- |
| `/docs/openapi.json` | 生成的 OpenAPI 3.0 spec（JSON） |
| `/docs` | Swagger UI 浏览页（默认经 unpkg CDN 加载静态资源） |

```php
// config/apidoc.php
return [
    'enabled'   => true,
    'title'     => env('APP_NAME', 'Kode Framework API'),
    'version'   => '1.0.0',
    'servers'   => [],          // 留空则由 Swagger UI 按当前 host 补全
    'json_path' => '/docs/openapi.json',
    'ui_path'   => '/docs',
    'ui'        => 'cdn',       // 当前仅支持 cdn
    'protect'   => 'none',      // none | token | local
    'token'     => env('API_DOC_TOKEN', ''),
    'ignore_paths' => ['/health', '/metrics', '/ping'],
];
```

- `protect=token`：`?token=` 或 `Authorization: Bearer <token>`；
- `protect=local`：仅 `127.*` / `::1`；
- `protect=none`：直接开放（仅建议内网/开发）。

## 二、自动生成内容

生成器消费 kode/http 的已注册路由，产出：

- `info`：来自 `config/apidoc.php`（title / version / description / contact）；
- `paths`：按路径聚合，**方法 → operation**；
- `operationId`：命名路由优先，否则 `{method}_{slug}`；
- `parameters`：路径参数（kode/http 的 `{id}` 语法与 OpenAPI 一致；`{page?}` 标记为 `required:false`）；
- `HEAD` / `OPTIONS` 自动跳过（GET 隐式附带 HEAD）。

```php
use Kode\Framework\Facades\ApiDoc;

$spec = ApiDoc::generate();   // 返回 spec 数组
$json = ApiDoc::toJson();     // 格式化 JSON（供端点返回）
// 或助手：openapi()->generate()
```

## 三、用 #[OpenApi] 补充语义

控制器方法上的属性用于补充「路由定义无法推断」的信息：

```php
use Kode\Framework\ApiDoc\Attributes\OpenApi;
use Kode\Framework\Http\Attributes\Get;

#[Get('/{id:\d+}', name: 'product.show')]
#[OpenApi(
    summary: '获取商品详情',
    description: '按商品 ID 返回商品信息',
    tags: ['product'],
    responses: [
        200 => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]],
        404 => ['description' => '商品不存在'],
    ],
)]
public function show() { /* ... */ }
```

支持字段：`summary` / `description` / `tags` / `requestBody`（OpenAPI 片段）/ `responses`（覆盖默认 `200`）/ `deprecated`。

> 属性由 `ControllerScanner` 在扫描期登记到 `RouteRegistry`，生成器读取后合并进对应 operation。

## 四、与第三方工具集成

- 把 `/docs/openapi.json` 作为 CI 产物，喂给 `openapi-generator`、`Redoc`、`Postman` 等；
- 私有化部署：将 `config/apidoc.php` 的 `ui` 改为自托管 Swagger UI，避免依赖公网 CDN。
