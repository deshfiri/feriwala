<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Order\Actions\ConfirmOrderPayment;
use App\Domain\Order\Models\Order;
use App\Domain\Supplier\Models\SupplierPayable;
use Carbon\CarbonImmutable;

/**
 * Records that an order's payment settled, against every Supplier payable it
 * carries (D25, P13-22).
 *
 * Called from {@see ConfirmOrderPayment} once the
 * order itself has moved to Paid and its stock committed — never before, and
 * never for an order that settlement is holding. Settling the payment alone
 * does not make a payable eligible; only {@see
 * EvaluateSupplierPayableEligibility} decides that, once delivery has also
 * been recorded. Idempotent: a payable that already has its
 * `payment_settled_at` is left exactly as it is.
 */
class QualifySupplierPayablePayment
{
    public function __construct(
        protected EvaluateSupplierPayableEligibility $eligibility,
    ) {}

    public function handle(Order $order, ?CarbonImmutable $at = null): void
    {
        $payables = SupplierPayable::query()->where('order_id', $order->id)->get();

        foreach ($payables as $payable) {
            $this->eligibility->markPaymentSettled($payable, $at);
        }
    }
}
