<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Package\Entitlements;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Website\Actions\SelectProductForWebsite;
use App\Domain\Website\Actions\SetWebsiteProductPublication;
use App\Domain\Website\Actions\UpdateWebsiteProduct;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Domain\Website\Queries\WebsiteCatalogue;
use App\Domain\Website\Queries\WebsiteOverview;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Concurrency\Exceptions\LockTimeout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The products one storefront sells (§15, §15.1, P5-1–P5-7).
 *
 * **Selection, never authorship.** Every product here already exists in
 * Feriwala's catalogue; this screen records which of them one shop sells, at
 * what price within the administrator's bounds, in what order, and whether they
 * are on sale. Nothing here creates a product — §16.3 forbids it and §12 makes
 * catalogue authorship a platform privilege (P5-14).
 *
 * Self-scoped like every other website route, and the selection is found inside
 * the website, which is found inside the account.
 */
class WebsiteProductController extends Controller
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected WebsiteOverview $overview,
        protected WebsiteCatalogue $catalogue,
        protected Entitlements $entitlements,
        protected ProductEligibility $eligibility,
    ) {}

    public function index(Request $request, string $website): Response
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'category' => $request->string('category')->toString() ?: null,
        ];

        $package = $this->entitlements->activePackage($record->businessAccount)?->package;
        $selections = $this->catalogue->selections($record, $filters);

        $published = WebsiteProduct::query()
            ->forAccount($record->businessAccount)
            ->published()
            ->count();

        return Inertia::render('websites/products', [
            'website' => $this->overview->summary($record),
            // The whole paginator, so the table has its range and page size.
            'selections' => $selections->through(
                fn (WebsiteProduct $selection) => $this->catalogue->row($selection, $package),
            ),
            'filters' => $filters,
            'categories' => $this->catalogue->categories($record),
            'statuses' => array_map(fn (WebsiteProductStatus $status) => [
                'value' => $status->value,
                'label' => __('website.product_statuses.'.$status->value),
            ], WebsiteProductStatus::cases()),

            // What §8.1 allows, said in words rather than by a button going
            // missing when the limit is reached.
            'publishing' => [
                'limit' => $this->entitlements->limit($record->businessAccount, PackageFeature::ProductPublishLimit),
                'used' => $published,
                'remaining' => $this->entitlements->remaining(
                    $record->businessAccount,
                    PackageFeature::ProductPublishLimit,
                    $published,
                ),
            ],
        ]);
    }

    /**
     * Choose a catalogue product for this storefront (§15, P5-2).
     */
    public function store(Request $request, string $website, SelectProductForWebsite $select): RedirectResponse
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $validated = $request->validate([
            'product' => ['required', 'string', 'size:26'],
        ]);

        // Found through the eligibility query rather than by identifier alone:
        // a product this account may not see is a 404, not a refusal that
        // confirms it exists (§12, §31.3).
        /** @var Product|null $product */
        $product = $this->eligibility
            ->query($record->businessAccount, SalesChannel::Dropshipping)
            ->where('public_id', $validated['product'])
            ->first();

        abort_if($product === null, 404);

        $this->attempt(fn () => $select->handle($record, $product, $this->person($request)));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.product_selected')]);

        return back();
    }

    /**
     * Price it, place it, describe it (§15.1, P5-4, P5-6).
     */
    public function update(
        Request $request,
        string $website,
        string $selection,
        UpdateWebsiteProduct $update,
    ): RedirectResponse {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $chosen = $this->selectionFor($record, $selection);

        $validated = $request->validate([
            // Minor units, because money is never a float and the browser never
            // computes one (§36.1).
            'price' => ['sometimes', 'integer', 'min:0'],
            'promotional_price' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'promo_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'marketing_description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'website_category_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'is_featured' => ['sometimes', 'boolean'],
        ]);

        $this->attempt(fn () => $update->handle($chosen, $this->person($request), $validated));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.product_updated')]);

        return back();
    }

    /**
     * Put it on sale, or take it off (§15, §8.1, P5-2, P5-3).
     */
    public function updatePublication(
        Request $request,
        string $website,
        string $selection,
        SetWebsiteProductPublication $publication,
    ): RedirectResponse {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $chosen = $this->selectionFor($record, $selection);

        $validated = $request->validate([
            'published' => ['required', 'boolean'],
        ]);

        $person = $this->person($request);
        $publish = (bool) $validated['published'];

        $this->attempt(fn () => $publish
            ? $publication->publish($chosen, $person)
            : $publication->unpublish($chosen, $person));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $publish
                ? __('website.flash.product_published')
                : __('website.flash.product_unpublished'),
        ]);

        return back();
    }

    /**
     * Stop selling it here. The catalogue product is untouched.
     */
    public function destroy(Request $request, string $website, string $selection): RedirectResponse
    {
        $record = $this->websiteFor($request, $website);

        Gate::authorize('manage', $record);

        $this->selectionFor($record, $selection)->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.flash.product_removed')]);

        return back();
    }

    /**
     * @param  callable(): mixed  $step
     */
    protected function attempt(callable $step): void
    {
        try {
            $step();
        } catch (WebsiteRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        } catch (LockTimeout) {
            throw ValidationException::withMessages(['product' => __('website.refused.busy')]);
        }
    }

    protected function selectionFor(Website $website, string $selection): WebsiteProduct
    {
        /** @var WebsiteProduct|null $record */
        $record = WebsiteProduct::query()
            ->where('website_id', $website->id)
            ->where('public_id', $selection)
            ->with(['website.businessAccount', 'product'])
            ->first();

        abort_if($record === null, 404);

        return $record;
    }

    protected function websiteFor(Request $request, string $website): Website
    {
        $record = $this->overview->findForAccount($this->businessAccountFor($request), $website);

        abort_if($record === null, 404);

        return $record;
    }

    protected function person(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
