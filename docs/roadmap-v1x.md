# kode/framework 生产级（1.x）路线规划

> 基准版本：**v0.8.51**（2026-08-25 · 425 测试全绿 · 真机与 webman/hyperf 对标数据已回填）。
> 目标：在**可验收的数据门禁**下推进到 `1.0.0`，对齐生产级框架的发布标准（BC 承诺、稳定 API 面、可复现基准）。

## 1. 现状一页

| 维度 | 状态 |
| --- | --- |
| 版本 | v0.8.51（`src/Application.php` VERSION，VCS tag 管理，composer.json 无 version 字段） |
| 测试 | 426 tests / 26,430 assertions：425 通过 + 1 Error（`QueueFacadeTest`，仅因沙箱缺 redis 扩展，真机有依赖即过）+ 1 Skipped |
| vs webman（同 4 进程 native，真机 v0.8.51） | 裸内核 /ping **185,362 vs 190,432（−2.7% 持平）**；能力对等档 **157,632 vs 127,564（+23.6%）**；L5 全开 123,175 与 webman ON 同档 |
| vs hyperf（单进程协程，真机 v0.8.51） | kode@Swoole 裸内核 /ping **186,630 vs 176,392（+5.8%）**、/bench/json **162,843 vs 147,051（+10.7%）**、MySQL 58,866 vs 28,189（+108.8%） |
| 性能基线存档 | 见 [docs/benchmarks.md](benchmarks.md)（v0.8.51 真机结论 + 压测口径，可复现） |
| 待办（真机） | `composer update kode/http` 对齐 3.4.11；`vendor/bin/phpunit` 全量复核；数据门禁复测 ×2 |

## 2. 两条横比规程（长期遵守，写入 CI 前先人工执行）

> `benchmarks/` 目录已随 v0.8.51 移除（见 §5），原 `peers/run.sh` 等编排脚本不再入库；
> 需要复核横比时按 [docs/benchmarks.md](benchmarks.md) §一 的口径重建 harness，或先在真机留档一份外部副本。

### 2.1 webman：同进程数 native 对标（kode 自研 process 引擎）

- **口径**：冷却式编排（peer 启动冷却 24s、peer 间 12s、端口级强杀），
  `wrk -t8 -c200 -d6s` ×3 中位，**同 worker 数**（默认 4，可 `WORKERS=` 覆盖）。
- **可比档位**：
  - 裸内核：kode `KODE_PROFILE=off` vs webman 零中间件；
  - 能力对等档：kode `cors,security,locale,logging` ≈ webman 默认 4 中间件；
  - L5 全开：kode 全量 vs webman ON（记录「多 3 档能力还持平」的结论，不做能力削足适履）。
- **判定**：相对 webman ≥ **95%** 视为持平，≥100% 为反超；**看比值不看绝对数**（跨机器/跨构建不可比）。

### 2.2 hyperf：单进程协程对标（kode/fibers 协程引擎）

- **口径**：kode 跑 **Swoole 或 Fiber 单进程**（`KODE_RUNTIME=swoole|fiber`，workers=1）vs hyperf 单进程，
  同端 `/ping`、`/bench/json`、`/bench/db`（MySQL 主键 SELECT→JSON，DB 完整性 1:1 校验）。
- **审计结论**：kode/fibers `Scheduler::yieldNow()` 已是**零分配快路径**（就绪队列直投 + 归属校验推迟），
  调度层无低垂果实；差距不来自调度器。真机复测 kode@Swoole vs hyperf 即可给最终数。
- **判定**：≥ **95%** 持平、≥100% 反超；剩余差距逐条归因（PSR-7 对象构造 / 响应物化 / 连接池），不归「协程机制」。

## 3. 到 1.0.0 的路线（每阶段有退出条件）

| 阶段 | 退出条件 | 主要动作 |
| --- | --- | --- |
| **0.9.0 稳定 API 面** | 公开 API 冻结清单评审通过（`src/` 顶层类/接口/服务提供者签名），BC 承诺文档建立 | 1) 评审 `Application/Http/Server/Providers` 面；2) 不兼容变更在 0.9.x 内完成并以 upgrade notes 记录；3) 建立 `UPGRADING.md` |
| **0.9.x 数据门禁** | 真机全量复测 ×2（间隔 ≥1 周）结果稳定；vendor 问题闭环 | 1) `composer update kode/http` 对齐 3.4.11；2) kode/database 池型检测修正（`isCoroutineRuntime`，见 `docs/dev/kode-package-issues.md` §B1，P1）落地后回测；3) 425+ 测试全绿桌面复跑 |
| **0.9.x 工程门禁** | CI（GitHub Actions：PHP 8.2/8.3/8.4 × swoole 矩阵）绿；覆盖率门禁达标；文档与版本一致性自动化 | 1) 建 CI：phpunit 全量 + 微基准回归（≥ 阈值，防热路径回退）；2) 加「版本一致性检查」（lock vs 包内 VERSION）；3) docs/benchmarks 链路指向最新版（本仓库已同步 v0.8.51，后续升版同步更新） |
| **1.0.0 发布** | 全部门禁绿 + tag `v1.0.0` + release notes | 1) 冻结性能基线为发布附件归档；2) 发布后 1.x = 语义化版本（minor 加功能、patch 修缺陷，BC 承诺生效） |

> **对「最低 1.x+ 才能正式对外」的回应**：同意，且规划即为此服务。0.9.x 期间不承诺 BC；
> 1.0.0 发布即对外宣称「生产可用、BC 承诺」，因此 1.0.0 之前的 API 冻结评审是**硬门槛**。

## 4. 本轮清理与决策（2026-08-25 执行结果）

| 项 | 决策 | 依据 |
| --- | --- | --- |
| `benchmarks/` 整体（88 文件） | **已删除**（`git rm`） | 用户明确：生产仓库不再携带压测工具链；压测口径与基线结论归档至 `docs/benchmarks.md`，真机复测前先留档外部副本即可重建 |
| `config/response.php` | **已并入 `config/http.php`** 后删除 | `error_keys`/`production_message` 全仓零引用，合并为 http 主题配置 |
| `app/` 目录 | **已全部小写化**（Http→http、Console→console、Tasks→tasks 等 8 目录） | 约定：目录全小写、方法文件首字母大写驼峰；PSR-4 `app\ => app/` 兼容 |
| `app/console/Commands/` | **已拉平为 `app/console/`**（单层） | 嵌套两层无必要；扫描/生成/Make 模板/测试已同步 |
| `.env` / `config/app.php` | **`APP_DEBUG` 默认 `false`** | 实测 DEBUG=false 桥接链路 292,530 vs 275,519 ops/s（**−5.8%**），生产默认关闭语义更优 |
| `docs/` 旧版本归档文档 | `kode-http-perf-3.4.10.md`、`fixes-vendor-kode.md` 已删除 | 数据收敛至 `docs/benchmarks.md` 与 `docs/dev/kode-package-issues.md` |
| `benchmarks/kode-package-issues.md` 等 | **迁移至 `docs/dev/`**（kode-package-issues.md / kode-process-issues.md） | vendor 问题清单是长期运维资产，不随 benchmarks 删除 |
| 过渡补丁 `patches/upstream/kode-http-3.4.10.patch` | 已删除（v0.8.50） | kode/http 3.4.10 官方 tag 已发布，vendor 纯净 |

## 5. 交付物与建议提交

- **代码/文档已就绪（未提交，等你确认）**：v0.8.51 全部改动（config 合并、app 小写化、console 拉平、benchmarks 删除、
  docs 重写为入门→高级体系、issues 迁移至 docs/dev/）。
- **建议提交信息**：`v0.8.51: 结构性治理——config 合并/app 目录小写化/console 拉平/benchmarks 移除/docs 重写为入门到高级体系；ADPDEBUG 默认关闭`
- **建议真机动作**：`composer update kode/http && vendor/bin/phpunit`，随后在 `docs/benchmarks.md` 追加一行 3.4.11 复核记录或新基线块。
- **建议发布序列**：0.9.0（API 冻结）→ 0.9.4（数据+工程门禁）→ 1.0.0（tag + 性能基线归档）。

## 6. 为什么现在适合进 0.9

- 性能差距（webman 31% 时代）已关闭并反超 DB 场景；剩余均为**正确性项**（database 池型检测、http 版本对齐），非性能项；
- 425 tests 全绿、文档口径统一到 v0.8.51、patch 清零、vendor 纯净、仓库结构完成生产化治理（app 小写约定、console 单层、无压测工具残留）；
- 缺的只是「1.0.0 该有的工程面」：CI、API 冻结评审、UPGRADING 文档、发布门禁——正是 0.9.x 阶段要做的事。