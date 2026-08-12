# 配置与环境变量

读配置：`config('jwt.guards.api.ttl')`、`config('app.debug', false)`（点号访问嵌套）。

写配置：`config/*.php` 返回数组；`.env` 用 `env('KEY', 'default')` 读取。

```dotenv
# .env
APP_DEBUG=true
APP_NAME=kode-app
JWT_SECRET=change-me
RATE_LIMIT_DRIVER=memory
```

配置期 `app()` 可能尚未就绪，路径类配置请写「相对项目根」的子路径（框架会用真实 `path.base` 拼成绝对路径），不要手写 `base_path()`。

---

