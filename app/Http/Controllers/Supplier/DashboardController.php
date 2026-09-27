<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Enums\PayableStatus;
use App\Domain\Supplier\Enums\SupplierWithdrawalStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierOfferStock;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Domain\Supplier\Models\SupplierWallet;
use App\Domain\Supplier\Models\SupplierWithdrawal;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureSupplierIsOperational;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Supplier portal's landing screen (D25, P13-1).
 *
 * Reachable regardless of application status — a Draft or Suspended Supplier
 * needs to see where their application stands. Operational actions (listing
 * submission, rates, stock) are their own routes, gated separately by
 * {@see EnsureSupplierIsOperational}; the snapshot cards below follow the same
 * boundary; a Supplier that is not yet operational sees none of them, since
 * every underlying screen already refuses it.
 *
 * Every figure is scoped by this Supplier's own `supplier_id` and nothing
 * else, matching every other Supplier-guarded controller (self-scoping is a
 * query concern, §31.3) — the wallet balance is read straight off
 * {@see SupplierWallet::availableBalance()}, never recomputed here.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        return Inertia::render('supplier/dashboard', [
            'status' => $supplier->status,
            'statusLabel' => $supplier->status->label(),
            'isOperational' => $supplier->isOperational(),
            'snapshot' => $supplier->isOperational() ? $this->snapshot($supplier) : null,
        ]);
    }

    /**
     * @return array{active_listings: int, pending_listings: int, active_offers: int, stock_available: int, withdrawals_pending: int, payables: array<string, mixed>, wallet: array<string, mixed>|null}
     */
    protected function snapshot(Supplier $supplier): array
    {
        $wallet = SupplierWallet::query()->where('supplier_id', $supplier->id)->first();
        $currency = Currency::BDT;

        return [
            'active_listings' => SupplierProductListing::query()
                ->where('supplier_id', $supplier->id)
                ->whereIn('status', [ListingStatus::Approved->value, ListingStatus::PartiallyApproved->value])
                ->count(),

            // Not yet decided either way — a correction request is still an
            // open round, not a closed one.
            'pending_listings' => SupplierProductListing::query()
                ->where('supplier_id', $supplier->id)
                ->whereIn('status', [
                    ListingStatus::Submitted->value,
                    ListingStatus::UnderReview->value,
                    ListingStatus::CorrectionRequired->value,
                ])
                ->count(),

            'active_offers' => SupplierOffer::query()
                ->where('supplier_id', $supplier->id)
                ->where('status', OfferStatus::Active->value)
                ->count(),

            'stock_available' => (int) SupplierOfferStock::query()
                ->whereHas('offer', fn ($query) => $query->where('supplier_id', $supplier->id))
                ->sum('quantity'),

            'withdrawals_pending' => SupplierWithdrawal::query()
                ->where('supplier_id', $supplier->id)
                ->whereIn('status', [SupplierWithdrawalStatus::Requested->value, SupplierWithdrawalStatus::UnderReview->value])
                ->count(),

            'payables' => $this->payableTotals($supplier, $currency),

            'wallet' => $wallet?->toBalances(),
        ];
    }

    /**
     * The same eligible/settled figures {@see WalletController}
     * shows on the dedicated wallet page — never a separate calculation.
     *
     * @return array<string, mixed>
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
}
