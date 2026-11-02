<?php

namespace App\Domain\Supplier\Queries;

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
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * The figures on a Supplier's landing screen (D25, P13-1), as typed values.
 *
 * The same counts and totals the web dashboard computes, scoped by the
 * Supplier's own `supplier_id` and nothing else (§31.3). Money stays a
 * {@see Money}; the caller decides how it is rendered.
 */
class SupplierDashboardSnapshot
{
    /**
     * @return array{
     *     active_listings: int,
     *     pending_listings: int,
     *     active_offers: int,
     *     stock_available: int,
     *     withdrawals_pending: int,
     *     payables: array{pending: Money, eligible: Money, settled: Money},
     *     wallet: array{total: Money, available: Money, available_for_withdrawal: Money, reserved: Money}|null,
     * }
     */
    public function for(Supplier $supplier): array
    {
        $wallet = SupplierWallet::query()->where('supplier_id', $supplier->id)->first();

        return [
            'active_listings' => SupplierProductListing::query()
                ->where('supplier_id', $supplier->id)
                ->whereIn('status', [ListingStatus::Approved->value, ListingStatus::PartiallyApproved->value])
                ->count(),

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

            'payables' => [
                'pending' => $this->payableTotal($supplier, PayableStatus::Pending),
                'eligible' => $this->payableTotal($supplier, PayableStatus::Eligible),
                'settled' => $this->payableTotal($supplier, PayableStatus::Settled),
            ],

            'wallet' => $wallet === null ? null : [
                'total' => $wallet->total,
                'available' => $wallet->availableBalance(),
                'available_for_withdrawal' => $wallet->availableForWithdrawal(),
                'reserved' => $wallet->reserved,
            ],
        ];
    }

    protected function payableTotal(Supplier $supplier, PayableStatus $status): Money
    {
        $sum = (string) (SupplierPayable::query()
            ->where('supplier_id', $supplier->id)
            ->where('currency_code', Currency::BDT->value)
            ->where('status', $status)
            ->sum('gross_amount') ?: '0');

        return Money::fromDecimal($sum, Currency::BDT);
    }
}
