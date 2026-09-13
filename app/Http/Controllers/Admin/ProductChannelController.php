<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\SetSalesChannel;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Switching dropshipping or wholesale on or off for a product (§11.1).
 *
 * One channel per request, named in the URL, so switching wholesale can never
 * touch dropshipping by accident. The permission is asked before anything is
 * read, and the action asks again.
 */
class ProductChannelController extends Controller
{
    public function __construct(
        protected SetSalesChannel $channels,
    ) {}

    public function update(Request $request, string $product, string $channel): RedirectResponse
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        $salesChannel = SalesChannel::tryFrom($channel);

        abort_if($salesChannel === null, 404);

        // Refused before validating: which way the switch goes is only read once
        // the person may switch a channel at all.
        abort_unless(CatalogPolicy::canSetChannel($actor, true) || CatalogPolicy::canSetChannel($actor, false), 403);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $enable = (bool) $validated['enabled'];

        abort_unless(CatalogPolicy::canSetChannel($actor, $enable), 403);

        /** @var Product $record */
        $record = Product::query()->where('public_id', $product)->firstOrFail();

        try {
            $this->channels->handle($actor, $record, $salesChannel, $enable, $validated['reason'] ?? null);
        } catch (CatalogRefused $refused) {
            throw ValidationException::withMessages(['channel' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __($enable ? 'catalog.channels.enabled' : 'catalog.channels.disabled', [
            'channel' => __('catalog.channels.'.$salesChannel->value),
        ])]);

        return back();
    }
}
