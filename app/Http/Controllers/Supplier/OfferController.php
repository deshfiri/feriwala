<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A Supplier's own approved products and their offers (D25, P13-13, P13-14).
 *
 * Scoped through the authenticated Supplier's own `offers()` relation, so one
 * Supplier can never see — let alone reach — another's offer, its rate, or
 * its stock.
 *
 * **The Platform Rate and platform margin are never shown here.** D25 is
 * explicit that the Supplier Rate is confidential from Client/Partner users;
 * the reverse boundary matters just as much — Feriwala's own margin is
 * nobody's business but the platform's, so this screen shows a Supplier only
 * what it agreed to be paid, never what the platform resells for.
 */
class OfferController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $offers = $supplier->offers()
            ->with(['product:id,public_id,name,slug', 'variant:id,public_id', 'stock'])
            ->orderByDesc('activated_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (SupplierOffer $offer) => $this->row($offer));

        return Inertia::render('supplier/offers/index', ['offers' => $offers]);
    }

    public function show(Request $request, string $offer): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $model = $supplier->offers()->with(['product', 'variant', 'stock', 'priceHistory'])->where('public_id', $offer)->firstOrFail();

        return Inertia::render('supplier/offers/show', [
            'offer' => [
                ...$this->row($model),
                'rate_history' => $model->priceHistory->map(fn ($change) => [
                    'supplier_rate' => $change->supplier_rate_minor->jsonSerialize(),
                    'effective_from' => $change->effective_from->toIso8601String(),
                ])->all(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(SupplierOffer $offer): array
    {
        return [
            'id' => $offer->public_id,
            'reference' => $offer->reference,
            'product_name' => $offer->product->name,
            'product_slug' => $offer->product->slug,
            'variant' => $offer->variant?->public_id,
            'status' => $offer->status->value,
            'is_preferred' => $offer->is_preferred,
            'wholesale_enabled' => $offer->wholesale_enabled,
            'dropshipping_enabled' => $offer->dropshipping_enabled,
            'supplier_rate' => $offer->supplier_rate_minor->jsonSerialize(),
            'available_quantity' => $offer->stock->quantity ?? 0,
        ];
    }
}
