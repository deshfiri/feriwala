<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Supplier\Actions\DecideSupplierStockUpdate;
use App\Domain\Supplier\Actions\SubmitSupplierStockUpdate;
use App\Domain\Supplier\Enums\StockUpdateStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The Supplier availability foundation, from the Supplier's own side (D25,
 * P13-15).
 *
 * Only a submission — nothing here moves `supplier_offer_stock.quantity`
 * directly; that happens only once staff approves through
 * {@see DecideSupplierStockUpdate}. Every offer
 * is reached through the authenticated Supplier's own `offers()` relation, so
 * one Supplier can never submit an update against another's offer.
 */
class StockController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $offers = $supplier->offers()
            ->with(['product:id,public_id,name', 'variant:id,public_id', 'stock', 'stockUpdates' => fn ($q) => $q->limit(5)])
            ->orderBy('id')
            ->get()
            ->map(fn (SupplierOffer $offer) => [
                'id' => $offer->public_id,
                'product_name' => $offer->product->name,
                'variant' => $offer->variant?->public_id,
                'available_quantity' => $offer->stock?->quantity ?? 0,
                'pending_update' => $offer->stockUpdates->firstWhere('status', StockUpdateStatus::Pending) !== null,
                'recent_updates' => $offer->stockUpdates->map(fn ($update) => [
                    'id' => $update->public_id,
                    'requested_quantity' => $update->requested_quantity,
                    'status' => $update->status->value,
                    'status_label' => $update->status->label(),
                    'decision_note' => $update->decision_note,
                    'created_at' => $update->created_at->toIso8601String(),
                ])->all(),
            ]);

        return Inertia::render('supplier/stock/index', ['offers' => $offers]);
    }

    public function store(Request $request, string $offer, SubmitSupplierStockUpdate $submit): RedirectResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $model = $supplier->offers()->where('public_id', $offer)->firstOrFail();

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $submit->handle($supplier, $model, $validated['quantity'], $validated['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }

        return back()->with('success', 'Availability update submitted for review.');
    }
}
