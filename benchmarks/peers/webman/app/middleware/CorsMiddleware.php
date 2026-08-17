<?php

declare(strict_types=1);

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Request;
use Webman\Http\Response;

class CorsMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        try {
            $response = $handler($request);
            $response = $response->withHeaders([
                'Access-Control-Allow-Origin'    => '*',
                'Access-Control-Allow-Methods'  => 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
                'Access-Control-Allow-Headers'  => 'Content-Type,Authorization,X-Requested-With,X-Request-Id',
                'Access-Control-Expose-Headers' => 'X-Request-Id,X-Trace-Id',
            ]);
            if (strtoupper($request->method()) === 'OPTIONS') {
                $response = $response->withStatus(204);
            }
            return $response;
        } catch (\Throwable $e) {
            file_put_contents('/tmp/mw_err.txt', "Cors: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
            throw $e;
        }
    }
}
