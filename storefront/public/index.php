<?php

declare(strict_types=1);

use Storefront\Http\Request;
use Storefront\Http\Response;
use Storefront\Http\View;
use Storefront\Kernel;
use Storefront\Support\Env;

require dirname(__DIR__).'/src/bootstrap.php';

// Security headers on everything. The CSP allows images from anywhere https
// because product media is served from the ERP's storage host (contract §5.1.0).
$send = static function (Response $response): void {
    $response->headers += [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        'Content-Security-Policy' => "default-src 'self'; img-src 'self' https: data:; style-src 'self' 'unsafe-inline'; script-src 'self'; form-action 'self' https:; base-uri 'self'; frame-ancestors 'none'",
    ];

    if (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $response->headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
    }

    $response->send();
};

try {
    $send(Kernel::handle(Request::capture()));
} catch (Throwable $throwable) {
    error_log('[storefront] '.get_class($throwable).': '.$throwable->getMessage().' @ '.$throwable->getFile().':'.$throwable->getLine());

    if (Env::bool('APP_DEBUG')) {
        $send(Response::text((string) $throwable, 500));

        return;
    }

    try {
        $send(View::render('errors/500', [], false, 500));
    } catch (Throwable) {
        $send(Response::text('Something went wrong. Please try again shortly.', 500));
    }
}
