# 部署到生产

```dotenv
# .env（生产）
APP_DEBUG=false
APP_NAME=myapp
JWT_SECRET=<强随机串>
RATE_LIMIT_DRIVER=redis
REDIS_HOST=127.0.0.1
```

```bash
# 用进程管理器拉起（不要用 --watch）
php bin/kode serve --port 80 --workers 8
```

- `APP_DEBUG=false`：错误响应自动收敛细节，只记日志。
- 关闭 `--watch`（那是开发热重载）。
- 多 worker 下，共享状态（限流 / 熔断 / 会话 / 缓存）请用 Redis 等外部存储。
- 用 supervisor / systemd 托管进程，保证崩溃自动重启。

---

