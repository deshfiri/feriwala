<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\TransitionProduct;
use App\Domain\Catalog\CentralProductFields;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Moving a product through its lifecycle (§11.2).
 *
 * Asks the move-specific permission before anything else, so a person who may
 * edit but not publish meets a 403 rather than a validation message. The action
 * asks again: this controller is not the only way in.
 */
class ProductStatusController extends Controller
{
    public function __construct(
        protected TransitionProduct $transition,
    ) {}

    public function update(Request $request, string $product): RedirectResponse
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        // Nobody without a catalogue write permission is told what the request lacked.
        abort_unless(CatalogPolicy::canWriteAny($actor), 403);

        /** @var Product $record */
        $record = Product::query()->where('public_id', $product)->firstOrFail();

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(array_map(
                fn (ProductStatus $status) => $status->value,
                ProductStatus::lifecycle(),
            ))],
            'reason' => ['nullable', 'string', 'max:2000'],

            // The lifecycle is this endpoint's; no other central field is (§12).
            ...CentralProductFields::rules(['status'], $request->all()),
        ], CentralProductFields::messages($request->all()));

        $to = ProductStatus::from($validated['status']);

        abort_unless(CatalogPolicy::canMoveProduct($actor, $record->status, $to), 403);

        try {
            $moved = $this->transition->handle($actor, $record, $to, $validated['reason'] ?? null);
        } catch (CatalogRefused|IllegalStateTransition $refused) {
            throw ValidationException::withMessages(['status' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.products.moved', [
            'name' => $moved->name,
            'status' => __('catalog.products.status.'.$to->value),
        ])]);

        return back();
    }
}
