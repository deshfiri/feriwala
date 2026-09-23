<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\SetPriceTiers;
use App\Domain\Catalog\CentralProductFields;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Rules\DecimalAmountRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Quantity pricing for a product or one of its variations (§11.1).
 *
 * §12 names the central wholesale price among what a regular user may not
 * modify, and a tier is a wholesale price — so this asks the catalogue's edit
 * permission, and a business account meets a 403.
 */
class ProductPriceTierController extends Controller
{
    public function __construct(
        protected SetPriceTiers $tiers,
    ) {}

    public function update(Request $request, string $product): RedirectResponse
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);
        abort_unless(CatalogPolicy::canEdit($actor), 403);

        /** @var Product $record */
        $record = Product::query()->where('public_id', $product)->firstOrFail();

        $validated = $request->validate([
            'variant_id' => ['nullable', 'string'],

            // Empty clears the table: the base price then applies at every quantity.
            // Entered in Taka; converted to minor units below, at this HTTP
            // boundary — SetPriceTiers still receives an integer per tier.
            'tiers' => ['nullable', 'array', 'max:'.SetPriceTiers::MAX_TIERS],
            'tiers.*.min_quantity' => ['required', 'integer', 'min:2', 'max:1000000', 'distinct'],
            'tiers.*.unit_price_minor' => ['required', new DecimalAmountRule],

            // The bands are this endpoint's; the base figures belong to the product form (§12).
            ...CentralProductFields::rules(['tiers'], $request->all()),
        ], CentralProductFields::messages($request->all()), [
            'tiers.*.min_quantity' => 'starting quantity',
            'tiers.*.unit_price_minor' => 'unit price',
        ]);

        $variant = null;

        if (filled($validated['variant_id'] ?? null)) {
            // Scoped to this product: another product's variation is a 404.
            $variant = ProductVariant::query()
                ->where('product_id', $record->id)
                ->where('public_id', $validated['variant_id'])
                ->firstOrFail();
        }

        /** @var array<int, array{min_quantity: int|string, unit_price_minor: int|string}> $tiers */
        $tiers = array_map(fn (array $tier) => [
            'min_quantity' => $tier['min_quantity'],
            'unit_price_minor' => DecimalAmount::parse($tier['unit_price_minor'])->minorUnits,
        ], array_values($validated['tiers'] ?? []));

        try {
            $this->tiers->handle($actor, $record, $variant, $tiers);
        } catch (CatalogRefused $refused) {
            throw ValidationException::withMessages(['tiers' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.tiers.saved')]);

        return back();
    }
}
