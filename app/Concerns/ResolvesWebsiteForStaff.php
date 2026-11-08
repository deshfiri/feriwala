<?php

namespace App\Concerns;

use App\Domain\Website\Models\Website;
use App\Domain\Website\Policies\WebsitePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Lets platform staff stand in for a partner on that partner's own website
 * screens.
 *
 * Used by the `Admin\` subclasses of the partner website controllers: the same
 * actions, the same rules and the same pages, with two differences. The website
 * is found among *every* website rather than among the signed-in account's own —
 * staff have no account — and the person must be allowed to administer it
 * (`website.edit`; the Super Admin passes every policy). The ordinary `manage`
 * check the parent controller then makes is already true for them, but is never
 * what lets a request in here: a partner reaching these routes would pass
 * `manage` for their own website, so `administer` is asked first and by name.
 *
 * Spending the partner's money — a setup charge, a renewal — is not among these
 * screens and stays the partner's own act ({@see WebsitePolicy::pay()}).
 */
trait ResolvesWebsiteForStaff
{
    protected function routePrefix(): string
    {
        return 'admin.';
    }

    protected function websiteFor(Request $request, string $website): Website
    {
        /** @var Website|null $record */
        $record = Website::query()->where('public_id', $website)->first();

        abort_if($record === null, 404);

        Gate::authorize('administer', $record);

        return $record;
    }
}
