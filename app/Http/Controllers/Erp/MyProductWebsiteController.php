<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Website\Actions\SetPurchasedProductOnWebsite;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Queries\WebsiteOverview;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Concurrency\Exceptions\LockTimeout;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Rules\DecimalAmountRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Putting a bought product on a storefront, from My Products (D34).
 *
 * One storefront at a time: the Partner's own, found inside their account, and a
 * product only if it is one they may sell. Anything else is a 404 rather than a
 * refusal that confirms it exists.
 */
class MyProductWebsiteController extends Controller
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected WebsiteOverview $overview,
        protected ProductEligibility $eligibility,
    ) {}

    public function update(Request $request, string $product, string $website, SetPurchasedProductOnWebsite $set): RedirectResponse
    {
        $account = $this->businessAccountFor($request);

        $record = $this->overview->findForAccount($account, $website);

        abort_if($record === null, 404);

        Gate::authorize('manage', $record);

        /** @var Product|null $found */
        $found = $this->eligibility
            ->query($account, SalesChannel::Dropshipping)
            ->where('slug', $product)
            ->first();

        abort_if($found === null, 404);

        // The price is the Partner's own, typed in Taka and checked against the
        // administrator's range in the action; this is only its shape.
        $validated = $request->validate([
            'published' => ['required', 'boolean'],
            'price' => ['nullable', new DecimalAmountRule],
        ]);

        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        $published = (bool) $validated['published'];

        $price = $published
            ? DecimalAmount::parseOrNull($validated['price'] ?? null)?->toDecimal()
            : null;

        $this->attempt($record, fn () => $set->handle($record, $found, $actor, $published, $price));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __($published ? 'website.flash.product_published' : 'website.flash.product_unpublished'),
        ]);

        return back();
    }

    /**
     * @param  callable(): mixed  $step
     */
    protected function attempt(Website $website, callable $step): void
    {
        try {
            $step();
        } catch (WebsiteRefused $refused) {
            // The page lists every storefront, so the refusal names which one.
            throw ValidationException::withMessages(['website' => $website->name.': '.$refused->getMessage()]);
        } catch (LockTimeout) {
            throw ValidationException::withMessages(['website' => __('website.refused.busy')]);
        }
    }
}
