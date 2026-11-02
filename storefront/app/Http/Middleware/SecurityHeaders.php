<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on everything. The CSP allows images from anywhere https
 * because product media is served from the ERP's storage host (contract §5.1.0).
 * The ERP's own origin is allowed as well: on a development machine it is plain
 * http, which `https:` alone would block — every product image and the hero banner.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' https: data:".$this->erpOrigin()."; style-src 'self' 'unsafe-inline'; script-src 'self'; form-action 'self' https:; base-uri 'self'; frame-ancestors 'none'",
        ];

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function erpOrigin(): string
    {
        $parts = parse_url((string) config('erp.base_url'));

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            return '';
        }

        return ' '.$parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
