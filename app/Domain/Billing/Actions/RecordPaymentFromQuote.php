<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Data\ActivationQuote;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
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
        BusinessAccount $account,
        ActivationQuote $quote,
        PaymentPurpose $purpose,
        ?string $idempotencyKey = null,
        ?Model $payable = null,
    ): Payment {
        try {
            return $this->database->transaction(
                fn () => $this->create($account, $quote, $purpose, $idempotencyKey, $payable)
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
        BusinessAccount $account,
        ActivationQuote $quote,
        PaymentPurpose $purpose,
        ?string $idempotencyKey,
        ?Model $payable,
    ): Payment {
        $payment = new Payment([
            'business_account_id' => $account->id,
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

        /*
         * The per-rate tax detail, alongside the single rolled-up Tax
         * allocation (D19).
         *
         * The rate and its basis points are copied in rather than referenced.
         * When the rate changes next year, this invoice must still say what it
         * said — and a tax return needs the taxable base as well as the tax,
         * neither of which is recoverable from a total.
         */
        foreach ($quote->taxBreakdown()->charges as $charge) {
            $payment->taxLines()->create($charge->toPaymentLine());
        }

        return $payment->load(['allocations', 'taxLines']);
    }
}
