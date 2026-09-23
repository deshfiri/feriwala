<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Billing\PaymentLogRedactor;
use App\Support\Money\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Throwable;

/**
 * Writes one payment-log entry (§42).
 *
 * The only way a row reaches `payment_logs`, so the redaction cannot be skipped
 * by a caller in a hurry. Everything in `context` goes through the redactor on
 * the way in — §42 forbids passwords, tokens, API secrets, gateway secrets,
 * complete payment credentials and unmasked personal data, and a rule enforced
 * at one door is a rule that holds.
 *
 * **Logging never breaks the thing it is logging.** A failure here is swallowed:
 * a settled payment must not be rolled back because its diary entry could not be
 * written, and an IPN must not be told to retry over a log row.
 */
class RecordPaymentLog
{
    public function __construct(
        protected PaymentLogRedactor $redactor,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array<array-key, mixed>  $context
     */
    public function handle(
        string $gateway,
        string $direction,
        string $event,
        ?Payment $payment = null,
        ?string $reference = null,
        ?string $gatewayReference = null,
        ?Money $amount = null,
        ?string $outcome = null,
        ?int $httpStatus = null,
        array $context = [],
        ?Request $request = null,
    ): ?PaymentLog {
        try {
            /*
             * Wrapped, so a failed insert cannot take the caller's transaction
             * with it. PostgreSQL aborts a whole transaction on any failed
             * statement, so catching the exception is not enough on its own —
             * inside a transaction this becomes a savepoint, and rolling back
             * to it leaves everything around it usable.
             */
            return $this->database->transaction(fn () => PaymentLog::create([
                'payment_id' => $payment?->id,
                'business_account_id' => $payment?->business_account_id,
                'gateway' => $gateway,
                'direction' => $direction,
                'event' => $event,
                'reference' => $reference ?? $payment?->reference,
                'gateway_reference' => $gatewayReference,
                'amount' => $amount,
                'outcome' => $outcome,
                'http_status' => $httpStatus,
                'context' => $this->redactor->redact($context),

                // The caller's address, for an inbound message. Useful when the
                // question is "who has been posting unsigned notifications".
                'ip_address' => $request?->ip(),

                'created_at' => now(),
            ]));
        } catch (Throwable) {
            // Deliberately silent. See the class docblock: the diary must never
            // be able to change what happened.
            return null;
        }
    }
}
