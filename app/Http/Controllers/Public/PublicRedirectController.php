<?php

namespace App\Http\Controllers\Public;

use App\Domain\Cms\Models\Redirect;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The public site's fallback route (§34.1) — reached only when nothing else
 * matched. Every admin-managed {@see Redirect} was already refused at
 * creation if it would chain into another one or point somewhere Feriwala
 * does not control (App\Domain\Cms\Actions\ManageRedirect, enforced again by
 * a database CHECK constraint), so serving one here is never more than a
 * single hop to a real, site-relative destination.
 *
 * A path with nothing configured for it 404s exactly as an unmatched route
 * always has — this never renders a page of its own.
 */
class PublicRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $path = '/'.ltrim($request->path(), '/');

        $redirect = Redirect::query()->enabledFor($path)->first();

        abort_unless($redirect !== null, 404);

        return redirect($redirect->to_path, $redirect->status_code);
    }
}
