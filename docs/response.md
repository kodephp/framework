# 响应对象

| 写法 | 效果 |
| --- | --- |
| `return ['k'=>'v'];` | 标准 JSON（默认） |
| `return $this->json($data);` | 标准成功 JSON |
| `return $this->error('出错了', 400);` | 标准错误，HTTP 400 + `{"message":"出错了"}` |
| `return $this->error('校验失败', 422, ['errors' => $e->errors()]);` | 带额外字段 |
| `return $this->response($data)->status(201)->header('X-A', '1');` | 链式自定义状态码 / 头 |
| `return Resp::json($data);` / `Resp::error($msg, 400);` | 全局助手（任何位置可用） |
| `return Resp::redirect('https://example.com');` | 302 重定向 |
| `return Resp::noContent();` | 204 |
| `return Response::make($rawBody, 200, ['Content-Type' => 'application/json']);` | 想完全绕过框架封装 |

> 想返回文件流 / 裸 JSON？直接返回 `Kode\Http\Response` 即可。

---

