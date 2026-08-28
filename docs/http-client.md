# HTTP 客户端（kode/http-client，PSR-18）

```php
$resp = Http::get('http://user-svc/1');        // 助手 http() 等价
$code = $resp->getStatusCode();
$body = $resp->getBody()->getContents();
$json = json_decode($body, true);

$resp = Http::post('http://user-svc', ['json' => ['name' => 'Kode']]);
```

