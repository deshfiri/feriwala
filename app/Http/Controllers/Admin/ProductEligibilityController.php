<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Actions\SetProductEligibility;
use App\Domain\Catalog\Enums\AccountScope;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Domain\Package\Models\Package;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Which packages and accounts may see a product (§11.1, §12).
 *
 * Deciding who a product is offered to is part of authoring it, so this asks the
 * catalogue's edit permission. A business account holder meets a 403 — a
 * partner cannot make a product visible to itself.
 */
class ProductEligibilityController extends Controller
{
    public function __construct(
        protected SetProductEligibility $eligibility,
    ) {}

    public function update(Request $request, string $product): RedirectResponse
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);
        abort_unless(CatalogPolicy::canEdit($actor), 403);

        /** @var Product $record */
        $record = Product::query()->where('public_id', $product)->firstOrFail();

        $validated = $request->validate([
            'package_scope' => ['required', Rule::enum(PackageScope::class)],
            'package_ids' => ['nullable', 'array', 'max:200'],
            'package_ids.*' => ['string', 'distinct', Rule::exists(Package::class, 'public_id')],

            'account_scope' => ['required', Rule::enum(AccountScope::class)],
            'account_ids' => ['nullable', 'array', 'max:500'],
            'account_ids.*' => ['string', 'distinct', Rule::exists(BusinessAccount::class, 'public_id')],
        ]);

        $this->eligibility->handle(
            $actor,
            $record,
            PackageScope::from($validated['package_scope']),
            array_values($validated['package_ids'] ?? []),
            AccountScope::from($validated['account_scope']),
            array_values($validated['account_ids'] ?? []),
        );

        return back()->with('success', __('catalog.eligibility.saved'));
    }
}
