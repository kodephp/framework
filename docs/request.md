# 请求对象

### 2.1 控制器短方法（日常首选）

```php
$this->input('name');          // GET + POST + JSON 合并；缺省 null
$this->input(['name','page']); // 只要这几个字段
$this->query('page');          // 仅 ?page=2
$this->post('payload');        // 仅请求体（含 JSON）
$this->params();               // 全部入参
$this->only('name','page');    // 字段筛选
$this->param('id');            // 路由路径参数
```

等价全局写法（服务 / 中间件里也能用）：`Request::input('name')`、`Request::get('fail')`、`Request::all()`。

### 2.2 完整 PSR-7 请求

需要 header / Cookie / 上传文件 / body 流时，用 `$req = $this->request()`：

```php
$token = $req->getHeaderLine('Authorization');
$ip    = $req->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
$ctype = $req->getContentType();
foreach ($req->getUploadedFiles() as $file) {
    $file->moveTo(storage_path('uploads/' . $file->getClientFilename()));
}
```

---

