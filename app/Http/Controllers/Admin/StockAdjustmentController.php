<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Inventory\Actions\AdjustStock;
use App\Domain\Inventory\Enums\StockAdjustmentKind;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Policies\InventoryPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Changing central stock by hand (§19, P3-24).
 *
 * `inventory.edit`, asked before any rule runs, so a refused person learns
 * nothing about what their request lacked. The reason is required here and
 * again in the action and the database, because a stock figure that changed for
 * no recorded reason is the first thing anybody reconciling stock asks about.
 */
class StockAdjustmentController extends Controller
{
    public function __construct(
        protected AdjustStock $adjust,
    ) {}

    public function store(Request $request, string $item): RedirectResponse
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);
        abort_unless(InventoryPolicy::canEdit($actor), 403);

        /** @var StockItem $record */
        $record = StockItem::query()->where('public_id', $item)->firstOrFail();

        $validated = $request->validate([
            'kind' => ['required', Rule::enum(StockAdjustmentKind::class)],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', 'string', 'min:'.AdjustStock::MINIMUM_REASON, 'max:1000'],
        ]);

        try {
            $this->adjust->handle(
                $actor,
                $record,
                StockAdjustmentKind::from($validated['kind']),
                (int) $validated['quantity'],
                $validated['reason'],
            );
        } catch (InventoryRefused $refused) {
            // "There are not that many" is an answer to what was asked.
            throw ValidationException::withMessages(['quantity' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('inventory.adjustments.saved')]);

        return back();
    }
}
