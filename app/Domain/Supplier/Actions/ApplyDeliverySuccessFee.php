<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Billing\DeliveryChargeSettings;
use App\Domain\Supplier\Data\SupplierPostingContext;
use App\Domain\Supplier\Models\DeliverySuccessFee;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Domain\Supplier\SupplierWalletService;
use App\Support\Money\Currency;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use RoundingMode;

/**
 * Charge the configured Delivery Success Fee against a supplier's own
 * payable, the moment its order reaches Delivered (D-new).
 *
 * Calculated on the payable's own `gross_amount` — the supplier's selling
 * price for the line, confirmed base — never the delivery charge, tax,
 * gateway fee or anything else. Applies identically for both Account Types,
 * and debited immediately, separately from whether the payable itself has
 * settled ({@see SupplierWalletService::debitFee()} mirrors {@see
 * SupplierWalletService::debitForReversal()} for exactly that reason).
 *
 * Idempotent by the unique `delivery_success_fees.supplier_payable_id`
 * index: one fee per payable, ever, regardless of repeated delivery
 * transitions, replayed webhooks or retried admin actions.
 */
class ApplyDeliverySuccessFee
{
    public function __construct(
        protected SupplierWalletService $wallets,
        protected OpenSupplierWallet $openWallet,
        protected DeliveryChargeSettings $settings,
        protected DatabaseManager $database,
    ) {}

    public function handle(SupplierPayable $payable): ?DeliverySuccessFee
    {
        $existing = DeliverySuccessFee::query()->where('supplier_payable_id', $payable->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $percent = $this->settings->deliverySuccessFeePercent();
        $baseAmount = $payable->gross_amount;
        $feeAmount = $baseAmount->percentage($percent, RoundingMode::HalfAwayFromZero);

        // A 0% configured rate charges nothing and leaves no decision row —
        // there is nothing to debit and nothing to freeze a rate against.
        if (! $feeAmount->isPositive()) {
            return null;
        }

        try {
            return $this->database->transaction(function () use ($payable, $percent, $baseAmount, $feeAmount) {
                $wallet = $this->openWallet->handle($payable->supplier, Currency::from($payable->currency_code));

                $entry = $this->wallets->debitFee($wallet, $feeAmount, new SupplierPostingContext(
                    source: 'delivery_success_fee',
                    description: "Delivery success fee for payable {$payable->reference}",
                    idempotencyKey: 'delivery-success-fee:'.$payable->public_id,
                    supplierPayableId: $payable->id,
                ));

                return DeliverySuccessFee::create([
                    'order_id' => $payable->order_id,
                    'order_item_id' => $payable->order_item_id,
                    'supplier_id' => $payable->supplier_id,
                    'supplier_payable_id' => $payable->id,
                    'base_amount' => $baseAmount,
                    'currency_code' => $baseAmount->currency->value,
                    'rate_percent' => $percent,
                    'fee_amount' => $feeAmount,
                    'supplier_ledger_entry_id' => $entry->id,
                    'created_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = DeliverySuccessFee::query()->where('supplier_payable_id', $payable->id)->first();

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }
}
