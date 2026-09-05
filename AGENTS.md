# AGENTS.md — AI 协作开发指南（kode/framework）

> 面向使用 AI 工具（opencode / Claude Code / Cursor / Copilot 等）在本仓库开发时的约定。
> 目标：让 AI 代理与人类开发者遵循同一套纪律，避免误改 vendor、破坏压测口径、制造版本不一致。

## 仓库是什么

- **kode/framework**：常驻内存 PHP 框架（**默认 kode/process 原生多进程**，可切 Swoole / Swow / Fiber），当前 **v1.2.3**。
- 依赖三组自研包（**vendor 内，只读**）：`kode/http`（HTTP 内核与路由）、`kode/process`（进程/运行时）、
  `kode/database`（数据库与连接池）、`kode/fibers`（协程调度）。
- 性能基线存档：`https://github.com/kodephp/docs/benchmarks.md`（v0.8.51 真机横比结论 + 压测口径，`benchmarks/` 目录已移除）；
  文档：`https://github.com/kodephp/docs/`（`README.md` 为索引，框架仓内 `docs/` 已通过 `export-ignore` 与分发分离）。

## 红线（AI 必须遵守）

1. **vendor/ 只读**：一律不改 `vendor/kode/*` 任何文件。发现包侧问题（性能/正确性/版本不一致）时，
   写入 `docs/dev/kode-package-issues.md`（http/database/fibers）或 `docs/dev/kode-process-issues.md`（process），
   只报告、不代改。framework 侧需要配合的改动在报告里给出方案，由包侧或用户确认。
2. **压测口径纪律**：跨机器/跨构建的**绝对 rps 不可直接比较**，结论只能以「同构建 A/B 相对比值」表述
   （webman ≥95% 持平、≥100% 反超）。wrk 压测统一 `-t8 -c200 -d6s ×3 中位` + 冷却式起停；
   口径细节见 `docs/benchmarks.md`，改口径必须改文档。
3. **版本一致性**：`src/Application.php` 的 `VERSION` 与 `docs/`、`README.md` 中出现的版本号必须同步；
   提交前检查 `vendor/composer/installed.php` 与包内 `composer.json`/`src/*VERSION*` 是否一致。
4. **不遗留补丁**：对 vendor 的过渡补丁在官方 tag 发布后必须移除并同步文档，vendor 保持纯净官方包。
5. **测试纪律**：任何 src 改动必须跑通 `vendor/bin/phpunit`（全量 425+ tests）。沙箱缺扩展的环境性失败
   （如 redis）如实标注，不编造「通过」。
6. **目录结构纪律（全层级小写）**：`app/` 下**所有目录一律小写**——不限于第一层，含 `http/controllers`、`http/middleware`、
   `providers`、`models` 等；方法/类文件首字母大写驼峰（如 `app/http/controllers/UserController.php`、`app/console/GreetCommand.php`）；
   console 命令单层存放于 `app/console/`（namespace `app\console`），不搞嵌套子目录。
   - **namespace 与目录的配套约定（勿打破）**：目录小写 ↔ namespace **全小写**一一对应（`app\http\controllers` ↔ `app/http/controllers/`）。
     `composer.json` 已配 `"app\\": "app/"`，PSR-4 直接命中，无需 classmap 兜底。因此：
     - `app/` 下新增/修改类走 PSR-4 即时可加载，**无需 `composer dump-autoload`**；仅当改了 `composer.json` 的 `autoload` 映射本身时才需刷新。属性路由在启动时递归扫描控制器目录自动注册，改完重启服务即生效。
     - 保持「目录小写 + namespace 小写 + 类名/方法名驼峰」一致即可，不要去把目录或 namespace 改成驼峰；
     - 改 `config/routes.php` 的 `attributes.controllers` 与 `src/Console/Commands/Make*Command.php` 生成路径时，必须继续用小写目录
       （`app/http/controllers`、`app/http/middleware`、`app/models`、`app/console`），并同步 tests 断言与 docs。
7. **字符卫生**：PHP/配置代码正文一律 ASCII 半角标点，禁止全角引号/逗号/分号混入源码；中文注释可用全角，代码符号必须半角。

## 常用命令

```bash
vendor/bin/phpunit                      # 全量测试（需 PHP ≥ 8.1 + 常用扩展）
php kode greet Kode                      # 命令自动发现冒烟（app/console/ 单层）
# 新增 app/ 类无需 dump-autoload（PSR-4 即时加载）；仅改 composer.json 的 autoload 时再跑（见红线 6）
```

## 文档索引（改文档前先读对应文件，勿另起炉灶）

| 话题 | 文件 |
| --- | --- |
| 性能基线（口径 + v0.8.51 真机结论） | `docs/benchmarks.md`（benchmarks/ 已移除，需复核时按口径重建 harness） |
| vendor 问题清单 | `docs/dev/kode-package-issues.md`（http/database）· `docs/dev/kode-process-issues.md`（process） |
| 1.x 生产化规划 | `docs/roadmap-v1x.md`（版本路线、横比规程、清理决策） |
| 主要能力 | `docs/aop.md` `docs/di.md` `docs/http-server.md` `docs/database.md` `docs/observability.md` 等（`docs/README.md` 全索引） |

## 通用约定

- **CLI 单一实现（勿再复制一份）**：CLI 唯一实现在**本仓库根目录 `kode`**（`bin/kode` 仅为兼容垫片，直接 `require ../kode`）。`kode/skeleton` 的 `kode` / `bin/kode`
  均为薄壳，只做定位 + 转发到 `vendor/kode/framework/kode`（优先）/`bin/kode`（兼容旧版）。历史上两份并存导致骨架里的
  `status` 长期停留在「打一次 /health」的老实现，框架的进程表 / 守护模式 / 快速排空一律缺席，
  且 `composer update` 拉不到。新增/修改 CLI 命令时**只改本仓库的根 `kode`**，骨架无需同步。
- **不主动提交**：git 提交仅在用户明确要求时执行；提交前 `git status` + `git diff` 复核范围。
- **合入前自查**：版本号、文档口径、测试结果、vendor 完整性（无 patch）、未提交遗留产物（`git status` 确认
  无临时文件/运行产物残留）全部就绪后再提交。