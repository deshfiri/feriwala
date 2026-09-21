<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Supplier\Actions\AdjustSupplierOfferStock;
use App\Domain\Supplier\Actions\DecideSupplierStockUpdate;
use App\Domain\Supplier\Enums\StockUpdateStatus;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierStockUpdate;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Staff review of Supplier availability (D25, P13-15). `supplier_stock.view`
 * to see it, `supplier_stock.edit` to approve, reject, or adjust. Central
 * warehouse stock is never read or written here.
 */
class SupplierStockController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeStock($request, PermissionAction::View);

        /** @var User $user */
        $user = $request->user();

        $updates = SupplierStockUpdate::query()
            ->with(['offer.supplier:id,public_id,business_name', 'offer.product:id,public_id,name', 'offer.stock'])
            ->where('status', StockUpdateStatus::Pending->value)
            ->orderBy('id')
            ->paginate(25)
            ->through(fn (SupplierStockUpdate $update) => [
                'id' => $update->public_id,
                'supplier' => $update->offer->supplier->business_name,
                'product_name' => $update->offer->product->name,
                'current_quantity' => $update->offer->stock?->quantity ?? 0,
                'requested_quantity' => $update->requested_quantity,
                'note' => $update->note,
                'created_at' => $update->created_at->toIso8601String(),
            ]);

        return Inertia::render('admin/supplier-stock/index', [
            'updates' => $updates,
            'can_edit' => $user->can(PermissionCatalogue::name(PermissionModule::SupplierStock, PermissionAction::Edit)),
        ]);
    }

    public function decide(Request $request, SupplierStockUpdate $stockUpdate, DecideSupplierStockUpdate $action): RedirectResponse
    {
        $this->authorizeStock($request, PermissionAction::Edit);

        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $validated['decision'] === 'approve'
                ? $action->approve($stockUpdate, $user->id, $validated['note'] ?? null)
                : $action->reject($stockUpdate, $user->id, $validated['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['decision' => $e->getMessage()]);
        }

        return back()->with('success', 'Decision recorded.');
    }

    public function adjust(Request $request, SupplierOffer $offer, AdjustSupplierOfferStock $action): RedirectResponse
    {
        $this->authorizeStock($request, PermissionAction::Edit);

        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $action->handle($offer, $user->id, $validated['quantity'], $validated['reason']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }

        return back()->with('success', 'Availability adjusted.');
    }

    protected function authorizeStock(Request $request, PermissionAction $action): void
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->can(PermissionCatalogue::name(PermissionModule::SupplierStock, $action)), 403);
    }
}
