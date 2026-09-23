<?php

namespace App\Domain\Wallet\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Wallet\Data\TopUpPlan;
use App\Support\Money\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The payment record behind a top-up (§26.3, P2-19).
 *
 * A payment like any other: the same table, the same statuses, and — the part
 * that matters — the same settlement. {@see SettlePayment}
 * remains the only thing that decides a payment has been made, so a top-up gets
 * the gateway verification, the amount check, the idempotency and the row
 * locking that every other payment gets, rather than a second path that would
 * have to be kept in step with the first.
 *
 * `revenue` is zero, and that is not an oversight. Feriwala keeps nothing
 * from a top-up: the money becomes the account's own balance. A report summing
 * revenue would otherwise count somebody's deposit as income.
 *
 * Idempotent on an unpaid attempt. Clicking twice reuses the open payment rather
 * than opening a second one; once it has been paid, the next top-up is a new
 * payment, because the money and the intent are both new.
 */
class RecordTopUpPayment
{
    public function __construct(
        protected DatabaseManager $database,
    ) {}

    public function handle(BusinessAccount $account, TopUpPlan $plan): Payment
    {
        $key = $this->keyFor($account, $plan->amount);

        try {
            return $this->database->transaction(fn () => Payment::create([
                'business_account_id' => $account->id,
                'purpose' => $plan->purpose,
                'status' => PaymentStatus::Draft,
                'amount' => $plan->amount,

                // Nothing is earned. The money becomes the account's balance.
                'revenue' => Money::zero($plan->amount->currency),
                'currency_code' => $plan->amount->currency->value,
                'idempotency_key' => $key,
            ]));
        } catch (UniqueConstraintViolationException $exception) {
            $existing = Payment::query()->where('idempotency_key', $key)->first();

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    /**
     * The identity of this attempt.
     *
     * Keyed on the account, the amount and the open attempt's own count, so a
     * double-submit of the same figure reuses one payment while a genuine second
     * top-up of the same amount tomorrow is a payment of its own.
     */
    protected function keyFor(BusinessAccount $account, Money $amount): string
    {
        $open = Payment::query()
            ->where('business_account_id', $account->id)
            ->whereIn('status', [PaymentStatus::Draft, PaymentStatus::Initiated, PaymentStatus::Pending])
            ->where('amount', $amount->toDecimal())
            ->whereIn('purpose', ['wallet_top_up', 'wallet_deposit'])
            ->orderByDesc('id')
            ->first();

        if ($open !== null && $open->idempotency_key !== null) {
            return $open->idempotency_key;
        }

        return 'wallet-top-up:'.$account->public_id.':'.$amount->toDecimal().':'.now()->timestamp;
    }
}
