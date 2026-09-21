<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Supplier\Actions\ActivateSupplierOffer;
use App\Domain\Supplier\Actions\SetPreferredSupplierOffer;
use App\Domain\Supplier\Actions\SetSupplierOfferRates;
use App\Domain\Supplier\Actions\SuspendSupplierOffer;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Staff management of Supplier offers, rates, and the preferred offer (D25,
 * P13-13, P13-14). Everything here is `supplier_pricing.*`.
 */
class SupplierOfferController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', SupplierOffer::class);

        $offers = SupplierOffer::query()
            ->with(['supplier:id,public_id,business_name', 'product:id,public_id,name', 'variant:id,public_id,sku', 'stock'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('supplier'), fn ($q) => $q->whereHas('supplier', fn ($s) => $s->where('public_id', $request->string('supplier')->toString())))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (SupplierOffer $offer) => $this->row($offer));

        return Inertia::render('admin/supplier-offers/index', ['offers' => $offers]);
    }

    public function show(SupplierOffer $offer): Response
    {
        Gate::authorize('view', $offer);

        $offer->load(['supplier', 'product', 'variant', 'stock', 'priceHistory.changedBy']);

        return Inertia::render('admin/supplier-offers/show', [
            'offer' => [
                ...$this->row($offer),
                'can_edit' => Gate::allows('edit', $offer),
                'rate_history' => $offer->priceHistory->map(fn ($change) => [
                    'supplier_rate' => $change->supplier_rate_minor->jsonSerialize(),
                    'platform_rate' => $change->platform_rate_minor->jsonSerialize(),
                    'effective_from' => $change->effective_from->toIso8601String(),
                    'changed_by' => $change->changedBy?->name,
                    'reason' => $change->reason,
                ])->all(),
            ],
        ]);
    }

    public function setRates(Request $request, SupplierOffer $offer, SetSupplierOfferRates $action): RedirectResponse
    {
        Gate::authorize('edit', $offer);

        /** @var User $reviewer */
        $reviewer = $request->user();

        $validated = $request->validate([
            'supplier_rate_minor' => ['required', 'integer', 'min:0'],
            'platform_rate_minor' => ['required', 'integer', 'min:0'],
            'supplier_currency_code' => ['nullable', 'string', 'size:3'],
            'platform_currency_code' => ['nullable', 'string', 'size:3'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $supplierCurrency = Currency::from($validated['supplier_currency_code'] ?? $offer->currency_code);
            $platformCurrency = Currency::from($validated['platform_currency_code'] ?? $offer->currency_code);

            $action->handle(
                $offer,
                $reviewer->id,
                Money::of((int) $validated['supplier_rate_minor'], $supplierCurrency),
                Money::of((int) $validated['platform_rate_minor'], $platformCurrency),
                $validated['reason'],
            );
        } catch (InvalidArgumentException|\ValueError $e) {
            throw ValidationException::withMessages(['platform_rate_minor' => $e->getMessage()]);
        }

        return back()->with('success', 'Rates updated.');
    }

    public function activate(Request $request, SupplierOffer $offer, ActivateSupplierOffer $action): RedirectResponse
    {
        Gate::authorize('edit', $offer);

        /** @var User $reviewer */
        $reviewer = $request->user();

        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:1000']])['reason'] ?? null;

        try {
            $action->handle($offer, $reviewer->id, $reason);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', 'Offer activated.');
    }

    public function suspend(Request $request, SupplierOffer $offer, SuspendSupplierOffer $action): RedirectResponse
    {
        Gate::authorize('edit', $offer);

        /** @var User $reviewer */
        $reviewer = $request->user();

        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:1000']])['reason'] ?? null;

        try {
            $action->handle($offer, $reviewer->id, $reason);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', 'Offer suspended.');
    }

    public function makePreferred(Request $request, SupplierOffer $offer, SetPreferredSupplierOffer $action): RedirectResponse
    {
        Gate::authorize('edit', $offer);

        /** @var User $reviewer */
        $reviewer = $request->user();

        try {
            $action->handle($offer, $reviewer->id);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['offer' => $e->getMessage()]);
        }

        return back()->with('success', 'Preferred offer set.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(SupplierOffer $offer): array
    {
        return [
            'id' => $offer->public_id,
            'reference' => $offer->reference,
            'supplier' => $offer->supplier->business_name,
            'product_name' => $offer->product->name,
            'variant_sku' => $offer->variant?->sku,
            'status' => $offer->status->value,
            'is_preferred' => $offer->is_preferred,
            'wholesale_enabled' => $offer->wholesale_enabled,
            'dropshipping_enabled' => $offer->dropshipping_enabled,
            'supplier_rate' => $offer->supplier_rate_minor->jsonSerialize(),
            'platform_rate' => $offer->platform_rate_minor->jsonSerialize(),
            'platform_margin' => $offer->platformMargin()->jsonSerialize(),
            'available_quantity' => $offer->stock->quantity ?? 0,
        ];
    }
}
