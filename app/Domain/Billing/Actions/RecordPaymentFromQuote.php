<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Data\ActivationQuote;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Turns a quote into a stored payment (§5.1, §9).
 *
 * The payment and all its allocations are written in one transaction, so a
 * payment can never exist without the components that explain it — a total with
 * no breakdown would be unreportable and unrefundable, which is exactly what
 * §5.1 forbids.
 *
 * Idempotent by key. Two concurrent requests carrying the same key will both try
 * to insert; the unique index settles it, and the loser returns the row the
 * winner created rather than failing. An application-level check-then-insert
 * cannot do this — both callers would pass the check.
 */
class RecordPaymentFromQuote
{
    public function __construct(
        protected DatabaseManager $database,
    ) {}

    public function handle(
        User $user,
        ActivationQuote $quote,
        PaymentPurpose $purpose,
        ?string $idempotencyKey = null,
        ?Model $payable = null,
    ): Payment {
        try {
            return $this->database->transaction(
                fn () => $this->create($user, $quote, $purpose, $idempotencyKey, $payable)
            );
        } catch (UniqueConstraintViolationException $e) {
            // Another request got there first with the same key. Returning its
            // payment is the whole point of idempotency — the caller gets the
            // same answer either way.
            $existing = $idempotencyKey === null
                ? null
                : Payment::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }
    }

    protected function create(
        User $user,
        ActivationQuote $quote,
        PaymentPurpose $purpose,
        ?string $idempotencyKey,
        ?Model $payable,
    ): Payment {
        $payment = new Payment([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'status' => PaymentStatus::Draft,
            'amount_minor' => $quote->total(),
            'revenue_minor' => $quote->revenue(),
            'currency_code' => $quote->currency->value,
            'idempotency_key' => $idempotencyKey,
        ]);

        if ($payable !== null) {
            $payment->payable()->associate($payable);
        }

        $payment->save();

        foreach ($quote->lines as $index => $line) {
            $payment->allocations()->create([
                'type' => $line->type,
                'amount_minor' => $line->amount,
                'currency_code' => $line->amount->currency->value,
                'description' => $line->description,
                // Preserves the order shown at checkout, so the receipt reads
                // the same as the quote did.
                'sort_order' => $index,
            ]);
        }

        return $payment->load('allocations');
    }
}
