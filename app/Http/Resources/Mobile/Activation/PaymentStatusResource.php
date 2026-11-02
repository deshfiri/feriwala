<?php

namespace App\Http\Resources\Mobile\Activation;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Http\Resources\Mobile\MoneyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Where the account's latest payment attempt got to, and what to show next.
 *
 * @property array{payment: Payment|null, account: BusinessAccount} $resource
 */
class PaymentStatusResource extends JsonResource
{
    /**
     * @return array{
     *     payment: array{id: string, purpose: string, status: string, status_label: string, status_tone: string, amount: MoneyResource, gateway: string|null, is_settled: bool, is_open: bool, needs_reconciliation: bool, expires_at: string|null, completed_at: string|null}|null,
     *     account_status: array{value: string, label: string},
     *     next: string
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var Payment|null $payment */
        $payment = $this->resource['payment'];
        /** @var BusinessAccount $account */
        $account = $this->resource['account'];

        return [
            /** The latest payment this account started and has not finished, else its last activation payment. Null before any attempt. */
            'payment' => $payment === null ? null : [
                'id' => $payment->public_id,
                'purpose' => $payment->purpose->value,
                'status' => $payment->status->value,
                'status_label' => $payment->status->label(),
                'status_tone' => $payment->status->tone(),
                'amount' => new MoneyResource($payment->amount),
                'gateway' => $payment->gateway,
                /** The money has been confirmed. */
                'is_settled' => $payment->status->isSettled(),
                /** Still waiting on the gateway: keep polling. */
                'is_open' => in_array($payment->status, PaymentStatus::open(), true),
                /** Money arrived after the checkout closed and staff are sorting it out. Do not offer another payment. */
                'needs_reconciliation' => $payment->status->needsReconciliation(),
                'expires_at' => $payment->expires_at?->toIso8601String(),
                'completed_at' => $payment->completed_at?->toIso8601String(),
            ],
            'account_status' => [
                'value' => $account->status->value,
                'label' => $account->status->label(),
            ],
            /**
             * What the app should show: `onboarding` once the payment is settled, otherwise
             * `checkout` (still open, failed, lapsed or being reconciled — see the flags above).
             */
            'next' => $payment?->status->isSettled() === true ? 'onboarding' : 'checkout',
        ];
    }
}
