# 多进程 HTTP 服务

```bash
php bin/kode serve                          # 默认 http://127.0.0.1:9527，worker=CPU 核数
php bin/kode serve --port 8080 --workers 8
php bin/kode serve --watch                  # 开发期热重载（监听 .php 变化自动重启）
```

每个 worker 独立重建应用，数据库连接 / 缓存句柄 / JWT 密钥等可变状态按进程隔离。`--watch` 仅用于开发，生产不要用。

---


这些能力由对应的 kode 生态包提供，框架只做薄封装与接线。统一通过门面或全局助手使用。
完整 API 与配置项见各 `config/*.php` 与对应 kode 包的文档。

