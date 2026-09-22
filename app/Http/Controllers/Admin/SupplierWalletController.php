<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Supplier\Models\SupplierLedgerEntry;
use App\Domain\Supplier\Models\SupplierWallet;
use App\Domain\Supplier\SupplierWalletService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff, read-only visibility into a Supplier's wallet (D25, P13-23).
 *
 * Reuses `supplier_payable.view` rather than a new permission string — the
 * same financial-visibility-into-this-Supplier concern the payables screen
 * already gates, and every role that holds it today (`SupplierManager`)
 * needs this too. No manual adjustment exists here or anywhere staff-facing
 * in this batch: the only writers of a Supplier wallet are
 * {@see SupplierWalletService}'s own callers
 * (settlement, reversal, withdrawal reserve/release/pay).
 */
class SupplierWalletController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeView($request);

        $wallets = SupplierWallet::query()
            ->with('supplier:id,public_id,business_name')
            ->when($request->filled('supplier'), fn ($q) => $q->whereHas('supplier', fn ($s) => $s->where('public_id', $request->string('supplier')->toString())))
            ->when($request->filled('currency'), fn ($q) => $q->where('currency_code', $request->string('currency')->toString()))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (SupplierWallet $wallet) => [
                'id' => $wallet->public_id,
                'supplier' => $wallet->supplier->business_name,
                'supplier_id' => $wallet->supplier->public_id,
                'currency' => $wallet->currency_code,
                'total' => $wallet->total_minor->jsonSerialize(),
                'available' => $wallet->availableBalance()->jsonSerialize(),
                'reserved' => $wallet->reserved_minor->jsonSerialize(),
                'recovery' => $wallet->recovery_minor->jsonSerialize(),
                'has_outstanding_recovery' => $wallet->hasOutstandingRecovery(),
            ]);

        return Inertia::render('admin/supplier-wallets/index', ['wallets' => $wallets]);
    }

    public function show(Request $request, SupplierWallet $wallet): Response
    {
        $this->authorizeView($request);

        $wallet->load('supplier:id,public_id,business_name,reference');

        $entries = $wallet->ledgerEntries()
            ->with(['payable:id,public_id,reference', 'withdrawal:id,public_id,reference'])
            ->paginate(25)
            ->withQueryString()
            ->through(fn (SupplierLedgerEntry $entry) => [
                'id' => $entry->public_id,
                'reference' => $entry->reference,
                'type' => $entry->type->value,
                'type_label' => $entry->type->label(),
                'is_credit' => $entry->isCredit(),
                'amount' => $entry->amount()->jsonSerialize(),
                'balance_after' => $entry->balance_after_minor->jsonSerialize(),
                'reserved_after' => $entry->reserved_after_minor->jsonSerialize(),
                'recovery_after' => $entry->recovery_after_minor->jsonSerialize(),
                'description' => $entry->description,
                'internal_note' => $entry->internal_note,
                'related_payable_reference' => $entry->payable?->reference,
                'related_withdrawal_reference' => $entry->withdrawal?->reference,
                'created_at' => $entry->created_at->toIso8601String(),
            ]);

        return Inertia::render('admin/supplier-wallets/show', [
            'wallet' => [
                'id' => $wallet->public_id,
                'supplier' => $wallet->supplier->business_name,
                'supplier_reference' => $wallet->supplier->reference,
                'supplier_id' => $wallet->supplier->public_id,
                'currency' => $wallet->currency_code,
                'total' => $wallet->total_minor->jsonSerialize(),
                'available' => $wallet->availableBalance()->jsonSerialize(),
                'reserved' => $wallet->reserved_minor->jsonSerialize(),
                'recovery' => $wallet->recovery_minor->jsonSerialize(),
                'has_outstanding_recovery' => $wallet->hasOutstandingRecovery(),
            ],
            'entries' => $entries,
        ]);
    }

    protected function authorizeView(Request $request): void
    {
        $user = $request->user();

        abort_if($user === null, 403);
        abort_unless($user->can(PermissionCatalogue::name(PermissionModule::SupplierPayable, PermissionAction::View)), 403);
    }
}
