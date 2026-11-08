<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Wallet;

use App\Domain\Supplier\Enums\PayableStatus;
use App\Domain\Supplier\Enums\SupplierLedgerEntryType;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierLedgerEntry;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Domain\Supplier\Models\SupplierWallet;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Controllers\Api\Mobile\V1\Supplier\ResolvesSupplier;
use App\Http\Requests\Mobile\Supplier\Wallet\SupplierStatementRequest;
use App\Http\Resources\Mobile\MoneyResource;
use App\Http\Resources\Mobile\Supplier\Wallet\SupplierLedgerEntryResource;
use App\Http\Resources\Mobile\Supplier\Wallet\SupplierWalletResource;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A Supplier's own wallet and statement (D25, P13-23). Read-only: balances move
 * only through the actions that cause them.
 *
 * Every figure is read off the wallet's own arithmetic; the app never works one
 * out. Scoped by `supplier_id`, so a Supplier reaches only its own wallet and
 * ledger.
 */
class WalletController extends MobileController
{
    use ResolvesSupplier;

    /**
     * Wallet
     *
     * The balances (`wallet`, null until the first movement opens the wallet),
     * the supplier's payable totals by status, and the ten latest statement
     * entries.
     *
     * @response array{wallet: SupplierWalletResource|null, payable_totals: array{pending: MoneyResource, eligible: MoneyResource, settled: MoneyResource}, recent_entries: list<SupplierLedgerEntryResource>}
     */
    public function show(Request $request): JsonResponse
    {
        $supplier = $this->supplierFor($request);

        $wallet = SupplierWallet::query()->where('supplier_id', $supplier->id)->first();

        return new JsonResponse([
            'wallet' => $wallet === null ? null : new SupplierWalletResource($wallet),
            'payable_totals' => $this->payableTotals($supplier, Currency::BDT),
            'recent_entries' => $wallet === null ? [] : SupplierLedgerEntryResource::collection(
                $wallet->ledgerEntries()->with(['payable', 'withdrawal'])->limit(10)->get(),
            ),
        ]);
    }

    /**
     * Statement
     *
     * The supplier's ledger entries, newest first, 25 to a page, optionally
     * filtered by `type` and by `date_from` / `date_to`. `types` lists the values
     * `type` accepts.
     *
     * @response array{data: list<SupplierLedgerEntryResource>, links: array<string, string|null>, meta: array<string, mixed>, types: list<array{value: string, label: string}>}
     */
    public function transactions(SupplierStatementRequest $request): AnonymousResourceCollection
    {
        $supplier = $this->supplierFor($request);

        $wallet = SupplierWallet::query()->where('supplier_id', $supplier->id)->first();

        $entries = $wallet === null
            ? SupplierLedgerEntry::query()->whereRaw('1 = 0')->paginate(25)
            : $wallet->ledgerEntries()
                ->with(['payable', 'withdrawal'])
                ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')->toString()))
                ->when($request->date('date_from'), fn ($query, CarbonInterface $from) => $query->where('created_at', '>=', $from->startOfDay()))
                ->when($request->date('date_to'), fn ($query, CarbonInterface $to) => $query->where('created_at', '<=', $to->endOfDay()))
                ->paginate(25);

        return SupplierLedgerEntryResource::collection($entries->withQueryString())->additional([
            'types' => array_map(
                fn (SupplierLedgerEntryType $case) => ['value' => $case->value, 'label' => $case->label()],
                SupplierLedgerEntryType::cases(),
            ),
        ]);
    }

    /**
     * @return array<string, MoneyResource>
     */
    protected function payableTotals(Supplier $supplier, Currency $currency): array
    {
        $total = fn (PayableStatus $status) => new MoneyResource(Money::fromDecimal(
            (string) (SupplierPayable::query()
                ->where('supplier_id', $supplier->id)
                ->where('currency_code', $currency->value)
                ->where('status', $status)
                ->sum('gross_amount') ?: '0'),
            $currency,
        ));

        return [
            'pending' => $total(PayableStatus::Pending),
            'eligible' => $total(PayableStatus::Eligible),
            'settled' => $total(PayableStatus::Settled),
        ];
    }
}
