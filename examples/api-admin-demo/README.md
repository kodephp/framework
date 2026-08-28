# api-admin-demo：博客 API + 管理后台 示例

一个开箱即用的 Kode Framework 示例，演示如何用「注解路由 + DI + JWT + ORM」搭建：

- **公开 API**：文章列表（分页/筛选）、文章详情
- **管理后台 API**：登录、仪表盘统计、文章的增删改查（需 `admin` 角色）
- **单应用 / 多应用** 目录组织方式

> 说明：本示例随框架仓库提供，是「参考快照」，并非由框架命令在框架内部生成的独立工程。在独立目录 `composer install` 后即可运行（**尚未在全部环境跑通验证**）。对应教程见 [`docs/tutorial.md`](../../docs/tutorial.md)。

## 环境

- PHP >= 8.3（推荐开启 Swoole / Swow 扩展以获得常驻内存能力；不开启也能跑）
- Composer 2
- SQLite（默认，零依赖）或 MySQL

## 快速开始

```bash
cd examples/api-admin-demo

# 1. 安装依赖
composer install             # PSR-4 自动加载，app/ 下新增类无需 dump-autoload（属性路由启动时自动扫描）

# 2. 准备环境配置
cp .env.example .env
# 生产环境把 .env 里的 JWT_SECRET 改成随机长串：openssl rand -hex 32

# 3. 建表 + 填充数据
php bin/kode migrate
php bin/kode db:seed

# 4. 启动服务（默认 127.0.0.1:9527）
php bin/kode serve
```

调试时也可用 PHP 内置服务器：

```bash
php -S 127.0.0.1:8000 public/index.php
```

## 接口速览

| 方法 | 路径 | 说明 | 鉴权 |
| --- | --- | --- | --- |
| GET | `/health` | 健康检查 | 否 |
| GET | `/api/posts` | 文章列表（`?per_page=10&category=1&q=关键词`） | 否 |
| GET | `/api/posts/{id}` | 文章详情 | 否 |
| POST | `/api/auth/login` | 登录，返回 JWT | 否 |
| GET | `/api/auth/me` | 当前用户 | 是 |
| GET | `/admin/api/posts` | 后台文章列表 | admin |
| POST | `/admin/api/posts` | 新建文章 | admin |
| GET | `/admin/api/posts/{id}` | 文章详情 | admin |
| PUT | `/admin/api/posts/{id}` | 更新文章 | admin |
| DELETE | `/admin/api/posts/{id}` | 删除文章 | admin |
| GET | `/admin/api/dashboard` | 仪表盘统计 | admin |

默认管理员账号（来自 seeder）：`admin` / `admin123`

### 调用示例

```bash
# 登录拿 token
TOKEN=$(curl -s -X POST http://127.0.0.1:9527/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"admin123"}' | php -r 'echo json_decode(file_get_contents("php://stdin"))->token;')

# 带 token 访问后台
curl http://127.0.0.1:9527/admin/api/posts \
  -H "Authorization: Bearer $TOKEN"
```

## 目录结构

```
app/
  http/
    controllers/        # 公开 API 控制器（注解路由）
    middleware/          # 自定义中间件（AdminMiddleware）
  admin/
    http/controllers/    # 后台 API 控制器（独立目录 = 多应用思路）
  models/                # 模型 User / Category / Post
  routes.php             # 闭包路由（健康检查等）
config/
  app.php  database.php  jwt.php  routes.php
database/
  migrations/            # 迁移
  seeders/               # 填充
public/index.php         # PHP 内置服务器入口
bin/kode                 # 命令行入口
```
