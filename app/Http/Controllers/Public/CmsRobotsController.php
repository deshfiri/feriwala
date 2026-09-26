<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * §34.1, Stage 8 completion. Authenticated ERP and admin routes already
 * carry their own `noindex` directive per request (the `noindex` middleware
 * alias, `bootstrap/app.php`) — a bot cannot sign in to reach them regardless
 * — so this stays a minimal, correct file rather than a hand-maintained
 * Disallow list that silently falls out of step with routes/web.php.
 */
class CmsRobotsController extends Controller
{
    public function __invoke(): Response
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Sitemap: '.route('sitemap'),
        ];

        return response(implode("\n", $lines)."\n", 200)->header('Content-Type', 'text/plain');
    }
}
