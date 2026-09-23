<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Supplier\Enums\PayableStatus;
use App\Domain\Supplier\Enums\SupplierLedgerEntryType;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierLedgerEntry;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Domain\Supplier\Models\SupplierWallet;
use App\Http\Controllers\Controller;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A Supplier's own wallet (D25, P13-23).
 *
 * Every balance shown here is read straight off {@see SupplierWallet}'s own
 * arithmetic — nothing is recomputed in the browser. Scoped through the
 * authenticated Supplier's own `supplier_id` throughout; a Supplier reaches
 * only its own wallet and never another's ledger.
 */
class WalletController extends Controller
{
    public function show(Request $request): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $wallet = SupplierWallet::query()->where('supplier_id', $supplier->id)->first();
        $currency = Currency::BDT;

        return Inertia::render('supplier/wallet/index', [
            'wallet' => $wallet === null ? null : $this->summary($wallet),
            'payable_totals' => $this->payableTotals($supplier, $currency),
            'recent_entries' => $wallet === null ? [] : $wallet->ledgerEntries()->limit(10)->get()->map(
                fn (SupplierLedgerEntry $entry) => $this->entryRow($entry),
            )->all(),
        ]);
    }

    public function transactions(Request $request): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $wallet = SupplierWallet::query()->where('supplier_id', $supplier->id)->first();

        $entries = $wallet === null
            ? SupplierLedgerEntry::query()->whereRaw('1 = 0')->paginate(25)
            : $wallet->ledgerEntries()
                ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->toString()))
                ->when($request->filled('date_from'), fn ($q) => $q->where('created_at', '>=', $request->date('date_from')))
                ->when($request->filled('date_to'), fn ($q) => $q->where('created_at', '<=', $request->date('date_to')))
                ->paginate(25);

        return Inertia::render('supplier/wallet/transactions', [
            'entries' => $entries->withQueryString()->through(fn (SupplierLedgerEntry $entry) => $this->entryRow($entry)),
            'types' => array_map(
                fn ($case) => ['value' => $case->value, 'label' => $case->label()],
                SupplierLedgerEntryType::cases(),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(SupplierWallet $wallet): array
    {
        return [
            'id' => $wallet->public_id,
            'currency' => $wallet->currency_code,
            'total' => $wallet->total->jsonSerialize(),
            'available' => $wallet->availableBalance()->jsonSerialize(),
            'reserved' => $wallet->reserved->jsonSerialize(),
            'recovery' => $wallet->recovery->jsonSerialize(),
            'has_outstanding_recovery' => $wallet->hasOutstandingRecovery(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function payableTotals(Supplier $supplier, Currency $currency): array
    {
        $base = fn (PayableStatus $status) => (string) (SupplierPayable::query()
            ->where('supplier_id', $supplier->id)
            ->where('currency_code', $currency->value)
            ->where('status', $status)
            ->sum('gross_amount') ?: '0');

        return [
            'pending' => Money::fromDecimal($base(PayableStatus::Pending), $currency)->jsonSerialize(),
            'eligible' => Money::fromDecimal($base(PayableStatus::Eligible), $currency)->jsonSerialize(),
            'settled' => Money::fromDecimal($base(PayableStatus::Settled), $currency)->jsonSerialize(),
        ];
    }

    /**
     * The columns a Supplier's own statement may see — never an internal
     * note, never an idempotency key.
     *
     * @return array<string, mixed>
     */
    protected function entryRow(SupplierLedgerEntry $entry): array
    {
        return [
            'id' => $entry->public_id,
            'reference' => $entry->reference,
            'type' => $entry->type->value,
            'type_label' => $entry->type->label(),
            'is_credit' => $entry->isCredit(),
            'amount' => $entry->amount()->jsonSerialize(),
            'balance_after' => $entry->balance_after->jsonSerialize(),
            'description' => $entry->description,
            'related_payable_id' => $entry->payable?->public_id,
            'related_payable_reference' => $entry->payable?->reference,
            'related_withdrawal_id' => $entry->withdrawal?->public_id,
            'related_withdrawal_reference' => $entry->withdrawal?->reference,
            'created_at' => $entry->created_at->toIso8601String(),
        ];
    }
}
