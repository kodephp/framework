# API 文档自动化（OpenAPI + Swagger UI）

框架本地薄实现：扫描已注册路由生成 OpenAPI 3.0 spec，并提供 Swagger UI 浏览页；
开发者用 `#[OpenApi]` 结构化属性补充「自动生成无法推断」的查询参数、请求体、响应结构；
再用 `apidoc:generate` 命令主动落盘为可提交的 `openapi.json`（CI 产物 / 喂给下游工具）。

## 一、自动生成的边界（重要）

OpenAPI 规范里，只有「路由 + 路径参数」能从代码可靠推断；其余属于业务语义，
框架**不会**臆测，否则自动生成的文档会"看起来完整、实则失真"。

| 内容 | 来源 | 是否需要声明 |
| --- | --- | --- |
| `paths` / `methods` / `operationId` | 路由注册 | 自动 |
| 路径参数（`{id}`） | 路由模式 | 自动（含 `{id?}` → `required:false`） |
| 查询/Header/Cookie 参数 | — | **需 `#[OpenApi]` 声明** |
| 请求体字段 | — | **需 `#[OpenApi]` 声明** |
| 响应结构与示例 | — | **需 `#[OpenApi]` 声明**（默认仅 `200 => OK`） |

> 控制器方法通常只接收 `$req` 并自行 `param()`，反射拿不到查询参数与响应结构，
> 因此选择"显式声明 + 主动生成"而非"全自动猜测"。这也是 `apidoc:generate --check`
> 存在的意义：在 CI 中强制补全缺漏。

## 二、端点（运行时自动生成，供联调/Swagger UI）

| 路径 | 说明 |
| --- | --- |
| `/docs/openapi.json` | 实时生成的 OpenAPI 3.0 spec |
| `/docs` | Swagger UI 浏览页（默认经 unpkg CDN 加载静态资源） |

```php
// config/apidoc.php
return [
    'enabled'   => true,
    'title'     => env('APP_NAME', 'Kode Framework API'),
    'version'   => '1.0.0',
    'description' => '由 Kode Framework 自动生成的 API 文档',
    'contact'   => ['name' => 'Kode Framework'],
    'servers'   => [],          // 留空则由 Swagger UI 按当前 host 补全
    'json_path' => '/docs/openapi.json',
    'ui_path'   => '/docs',
    'ui'        => 'cdn',       // 当前仅支持 cdn
    'protect'   => 'none',      // none | token | local
    'token'     => env('API_DOC_TOKEN', ''),
    'ignore_paths' => ['/health', '/metrics', '/ping'],
    'output'    => 'storage/apidoc/openapi.json', // apidoc:generate 默认写出路径
];
```

- `protect=token`：`?token=` 或 `Authorization: Bearer <token>`；
- `protect=local`：仅 `127.*` / `::1`；
- `protect=none`：直接开放（仅建议内网/开发）。

## 三、用 #[OpenApi] 补充方法参数与响应数据

控制器方法上的属性用于声明"自动生成搞不定"的语义。配套三个值对象：
`OpenApiParameter`（参数）、`OpenApiRequestBody`（请求体）、`OpenApiResponse`（响应）。

```php
use Kode\Framework\ApiDoc\Attributes\OpenApi;
use Kode\Framework\ApiDoc\Attributes\OpenApiParameter;
use Kode\Framework\ApiDoc\Attributes\OpenApiRequestBody;
use Kode\Framework\ApiDoc\Attributes\OpenApiResponse;
use Kode\Framework\Http\Attributes\Get;
use Kode\Framework\Http\Attributes\Post;

#[Get('/{id:\d+}', name: 'product.show')]
#[OpenApi(
    summary: '获取商品详情',
    description: '按商品 ID 返回商品信息',
    tags: ['product'],
    // 方法参数：查询参数（路径参数 {id} 已由路由自动提取）
    parameters: [
        new OpenApiParameter('fields', 'query', 'string', description: '逗号分隔的返回字段'),
    ],
    // 响应数据：状态码 => 结构 + 示例（覆盖默认 200 => OK）
    responses: [
        200 => new OpenApiResponse(
            200, 'OK',
            properties: [
                'id'   => ['type' => 'integer', 'description' => '商品 ID'],
                'name' => ['type' => 'string', 'description' => '商品名称'],
            ],
            example: ['id' => 1, 'name' => 'Kode 键盘'],
        ),
        404 => new OpenApiResponse(404, '商品不存在'),
    ],
)]
public function show() { /* ... */ }

#[Post('')]
#[OpenApi(
    summary: '创建商品',
    tags: ['product'],
    // 请求体字段（对象属性 + 必填项 + 整体示例）
    requestBody: new OpenApiRequestBody(
        properties: [
            'name'  => ['type' => 'string'],
            'price' => ['type' => 'number'],
        ],
        required: ['name'],
        example: ['name' => '键盘', 'price' => 99.0],
    ),
    responses: [
        201 => new OpenApiResponse(201, '已创建', properties: ['id' => ['type' => 'integer']]),
        422 => new OpenApiResponse(422, '校验失败'),
    ],
)]
public function store() { /* ... */ }
```

### 字段说明

`#[OpenApi]` 支持：`summary` / `description` / `tags` / `parameters`（数组，元素为
`OpenApiParameter` 或原始数组）/ `requestBody`（`OpenApiRequestBody` 或原始 OpenAPI 片段）/
`responses`（以状态码为键的映射，值为 `OpenApiResponse` 或原始数组）/ `deprecated`。

各值对象：

- `OpenApiParameter(name, in='query', type='string', required=false, description=null, example=null)`
  — `in` 可为 `query` / `header` / `cookie` / `path`；与路由自动提取的路径参数按 `name|in` 去重合并。
- `OpenApiRequestBody(type='object', properties=[], required=[], description=null, example=null)`
  — `properties` 为 `字段名 => OpenAPI schema 片段`。
- `OpenApiResponse(status=200, description='OK', type='object', properties=[], example=null, headers=[])`
  — `properties` 同 `OpenApiRequestBody`。

> 属性由 `ControllerScanner` 在扫描期登记到 `RouteRegistry`，生成器读取后合并进对应 operation。

## 四、主动生成命令（开发者触发，而非仅运行时自动）

```bash
# 写入 config('apidoc.output')（默认 storage/apidoc/openapi.json）
php bin/kode apidoc:generate

# 指定输出路径（相对项目根或绝对路径）
php bin/kode apidoc:generate --output=docs/openapi.json

# 仅打印到标准输出，不落盘
php bin/kode apidoc:generate --no-write

# 校验完整性：缺 summary/description 或未声明 200 响应的操作会列出，并退出码 1
php bin/kode apidoc:generate --check
```

`--check` 的退出码约定：

- `0`：全部操作均有 `summary`/`description` 且声明了 `200` 响应；
- `1`：存在不完整操作（已在标准输出列出 `METHOD path — 原因`）。

建议在 CI 中加入 `apidoc:generate --check`，把"文档补全"纳入质量门禁。

## 五、编程式访问

```php
use Kode\Framework\Facades\ApiDoc;

$spec = ApiDoc::generate();   // 返回 spec 数组
$json = ApiDoc::toJson();     // 格式化 JSON（供端点返回）
// 或助手：openapi()->generate()
// 完整性检查（供自定义校验脚本）：
$issues = ApiDoc::findIncomplete($spec); // list<{path, method, reasons}>
```

## 六、与第三方工具集成

- 把 `apidoc:generate` 产出的 `openapi.json` 作为 CI 产物，喂给 `openapi-generator`、
  `Redoc`、`Postman` 等；
- 私有化部署：将 `config/apidoc.php` 的 `ui` 改为自托管 Swagger UI，避免依赖公网 CDN。
