# kode/framework 压测对比套件

对 kode/framework 做**整体功能压测与同类框架响应速度对比**的轻量工具，零额外依赖（仅压测脚本自带 `src/Bench.php`）。

## 目录结构

```
benchmarks/
├── run.php              # 编排器：启动各场景 → 测量 → 输出对比表 → 生成 report.md
├── report.md           # 生成的压测报告（含功能矩阵与解读）
├── src/Bench.php       # 计时工具（hrtime + 百分位 + req/s）
├── scenarios/
│   ├── kode.php        # kode/framework 场景（复制 config 到临时目录并关闭限流）
│   ├── baseline.php    # 裸 PHP 基线（纯业务逻辑，无框架）
│   └── slim.php        # Slim 4 对等场景（隔离装在 peers/slim，可选）
└── peers/slim/         # 隔离的 Slim 4 安装（composer.json，不污染框架 vendor）
```

## 运行

```bash
# 1) 仅 kode + 裸 PHP 基线（默认）
php -d opcache.enable_cli=1 benchmarks/run.php

# 2) 加入 Slim 4 真实对比（一次性安装对等框架）
cd benchmarks/peers/slim && composer install
php -d opcache.enable_cli=1 benchmarks/run.php

# 调整采样量
BENCH_ITERS=2000 BENCH_WARMUP=800 php benchmarks/run.php
```

## 测量口径

- **单进程内 boot 一次 + 循环 `handle(ServerRequest)`**：模拟常驻内存运行时的每请求成本，排除 HTTP 服务器与进程启动噪声。
- 限流在压测中强制关闭（避免高并发触发 429）；其余生产默认中间件保留，测得真实全栈成本。
- 指标：吞吐量（req/s）、p50/p95/p99 延迟（毫秒）。

## 关于数字

kode 在 trivial 路由上的单请求吞吐低于极简微框架（如 Slim），这是「电池全包企业框架」为开箱即用的韧性/可观测/分布式能力
支付的架构代价，详见 [`../docs/benchmarks.md`](../docs/benchmarks.md)。绝对 req/s 非同台竞技，请结合功能矩阵综合评估。
