<?php

namespace App\Http\Controllers\Api\Mobile\V1\Activation;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Actions\ReconcileGatewayPayments;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Resources\Mobile\Activation\PaymentStatusResource;
use Illuminate\Http\Request;

/**
 * What happened at the gateway, for an app that cannot be redirected back (§26.4).
 *
 * The web is sent back to a page inside its session; the app is not. It opens the
 * gateway in a browser view and, when that closes, asks here. The money is settled
 * by the gateway's own notification — nothing the app says on its way back decides
 * a payment — so this only reads, or asks the gateway directly the way the hourly
 * reconciliation sweep does.
 *
 * Scoped to the caller's own account: the payment is whichever one this account was
 * last in the middle of paying, never one named by the request (§31.3).
 */
class PaymentStatusController extends MobileController
{
    use ResolvesBusinessAccount;

    /**
     * Payment status
     *
     * The account's latest payment attempt (never an order's payment) with its status and
     * whether it is settled, still open or being reconciled, plus `next`, the screen to show.
     * Read only; poll it while `is_open` is true.
     */
    public function show(Request $request): PaymentStatusResource
    {
        $account = $this->businessAccountFor($request);

        return new PaymentStatusResource(['payment' => $this->paymentFor($account), 'account' => $account]);
    }

    /**
     * Check with the gateway
     *
     * Asks the gateway directly, by our own reference, whether the open payment went through —
     * the lookup the hourly reconciliation sweep makes, made now so a payer who has paid is not
     * left waiting. A payment that is confirmed is settled through the normal settlement path.
     * A gateway that cannot be reached or has no status lookup leaves the payment as it was.
     * Answers like `GET /checkout/payment`.
     */
    public function verify(Request $request, ReconcileGatewayPayments $reconcile): PaymentStatusResource
    {
        $account = $this->businessAccountFor($request);
        $payment = $this->paymentFor($account);

        if ($payment !== null && in_array($payment->status, PaymentStatus::open(), true)) {
            $reconcile->check($payment);
            $payment = $payment->refresh();
        }

        return new PaymentStatusResource(['payment' => $payment, 'account' => $account->refresh()]);
    }

    /**
     * The most recent payment this account started and has not finished; failing that, its last
     * activation payment. Never a wholesale or website order's payment.
     */
    protected function paymentFor(BusinessAccount $account): ?Payment
    {
        $open = Payment::query()
            ->where('business_account_id', $account->id)
            ->whereNotIn('purpose', [PaymentPurpose::WholesaleOrder, PaymentPurpose::WebsiteOrder])
            ->whereIn('status', PaymentStatus::open())
            ->latest('id')
            ->first();

        return $open ?? Payment::query()
            ->where('business_account_id', $account->id)
            ->where('purpose', PaymentPurpose::Activation)
            ->latest('id')
            ->first();
    }
}
