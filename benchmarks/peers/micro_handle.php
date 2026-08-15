<?php
/**
 * 隔离测量 kode·lean 的 $http->handle() 框架热路径上限（无 Swoole 事件循环、无网络）。
 * 用以判断 wrk 实测 159k rps 是「框架 handle 瓶颈」还是「Swoole/网络瓶颈」。
 *
 * 用法：php benchmarks/peers/micro_handle.php [iterations]
 */
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Kode\Framework\Application;
use Kode\Http\App;
use Kode\Framework\Http\Resp;
use Kode\Http\Psr7\Message\ServerRequest as KodeServerRequest;
use Kode\Http\Psr7\Uri;
use Kode\Http\Psr7\Stream;

$repoRoot = dirname(__DIR__, 2);
$profile = 'lean';
$disable = ['logging', 'session', 'idempotency', 'feature', 'cors', 'security', 'locale', 'resilience', 'observability', 'limiting'];
$keys = $disable;
$tmp = sys_get_temp_dir() . '/kode-micro-' . substr(md5($repoRoot . '|' . $profile . '|' . implode(',', $keys)), 0, 10);
if (!is_dir($tmp . '/config')) {
    mkdir($tmp . '/config', 0o777, true);
    foreach (glob($repoRoot . '/config/*.php') ?: [] as $file) {
        copy($file, $tmp . '/config/' . basename($file));
    }
    foreach ($disable as $k) {
        file_put_contents($tmp . '/config/' . $k . '.php', match ($k) {
            'logging'       => "<?php return ['access_log' => ['enabled' => false]];\n",
            'session'       => "<?php return ['enabled' => false];\n",
            'idempotency'   => "<?php return ['http' => ['enabled' => false]];\n",
            'feature'       => "<?php return ['enabled' => false];\n",
            'cors'          => "<?php return ['enabled' => false];\n",
            'security'      => "<?php return ['enabled' => false, 'audit' => ['enabled' => false], 'request_id' => false];\n",
            'locale'        => "<?php return ['enabled' => false];\n",
            'resilience'    => "<?php return ['breaker' => ['http' => ['enabled' => false]], 'retry' => ['http' => ['enabled' => false]]];\n",
            'observability' => "<?php return ['metrics' => ['enabled' => false], 'tracing' => ['enabled' => false]];\n",
            default         => "<?php return ['enabled' => false];\n",
        });
    }
}

$app = Application::make($tmp);
/** @var App $http */
$http = $app->http();
$http->get('/ping', static fn () => Resp::json(['status' => 'ok']));

// 预构造一个 /ping 请求（与生产 adapter 等价）
$uri = new Uri('/ping');
$psr = new KodeServerRequest('GET', $uri, [], [], Stream::create(''));

$iters = (int) ($argv[1] ?? 200000);
$ops = 0;
$t0 = hrtime(true);
for ($i = 0; $i < $iters; $i++) {
    $r = $http->handle($psr);
    $ops++;
}
$t1 = hrtime(true);
$sec = ($t1 - $t0) / 1e9;
printf("framework handle only: %d ops in %.3fs => %.0f ops/s (wrk lean = 158,925)\n", $ops, $sec, $ops / $sec);
