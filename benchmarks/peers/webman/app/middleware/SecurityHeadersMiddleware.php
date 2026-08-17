<?php

declare(strict_types=1);

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Request;
use Webman\Http\Response;

class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        try {
            $response = $handler($request);
            return $response->withHeaders([
                'X-Content-Type-Options'       => 'nosniff',
                'X-Frame-Options'              => 'DENY',
                'Referrer-Policy'              => 'strict-origin-when-cross-origin',
                'Strict-Transport-Security'    => 'max-age=31536000; includeSubDomains',
                'Content-Security-Policy'      => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
                'Permissions-Policy'           => 'geolocation=(), microphone=(), camera=(), payment=(), usb=()',
                'Cross-Origin-Opener-Policy'   => 'same-origin',
                'Cross-Origin-Resource-Policy' => 'same-origin',
            ]);
        } catch (\Throwable $e) {
            file_put_contents('/tmp/mw_err.txt', "Security: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
            throw $e;
        }
    }
}
