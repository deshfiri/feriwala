<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Billing\Models\RefundRequest;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Proof that a payment was made (§26.4).
 *
 * Not an invoice. An invoice says what is owed and is issued before anybody
 * pays; a receipt says what was actually taken, when, through which provider,
 * and against which transaction at their end. §8.2's invoices already exist;
 * this is the other document.
 *
 * **Built only from stored verified facts.** Every figure here comes from a
 * column that a server-to-server verification wrote, not from a gateway's
 * redirect, a webhook body, or anything a browser sent. A receipt is the
 * document somebody keeps for their accounts, and it must not be able to say
 * something the platform never confirmed.
 *
 * **What is deliberately absent** is as much the point as what is present. No
 * credentials, no signatures, no raw provider payloads, no internal notes, no
 * staff-only remarks (§7.3, §42). A receipt goes to the account holder, so it
 * carries nothing the account holder should not have — and nothing that would
 * leak if they forwarded it.
 */
readonly class PaymentReceipt
{
    private function __construct(
        public string $number,
        public string $paymentReference,
        public string $purpose,
        public CarbonImmutable $paidAt,

        /** @var array<int, array<string, mixed>> */
        public array $lines,

        /** @var array<string, mixed> */
        public array $amount,

        /**
         * What the provider actually settled, when it differs (D4).
         *
         * Present only when the provider reported a different currency or
         * figure from the one charged. Recording both is what stops a foreign
         * settlement being silently rewritten into the base ledger.
         *
         * @var array<string, mixed>|null
         */
        public ?array $settled,

        public string $gateway,

        /**
         * Whether this was taken in a provider's sandbox.
         *
         * On the receipt on purpose. A test payment must never be able to
         * produce a document that looks like proof of real money, and the mode
         * is stamped on the payment rather than read from today's settings.
         */
        public bool $isSandbox,

        /** The provider's own transaction, so a dispute can be traced. */
        public ?string $gatewayReference,

        /** @var array<int, array<string, mixed>> */
        public array $refunds,

        /** @var array<string, mixed>|null */
        public ?array $refundedTotal,
    ) {}

    /**
     * Build a receipt for a settled payment.
     *
     * Returns null for anything that has not settled — there is no such thing
     * as a receipt for money that did not arrive, and issuing one would be the
     * platform vouching for a payment it never confirmed.
     */
    public static function forPayment(Payment $payment): ?self
    {
        if (! $payment->status->isSettled() && ! self::isRefunded($payment)) {
            return null;
        }

        $paidAt = $payment->completed_at;

        if ($paidAt === null) {
            // Settled with no completion time is a record that cannot say when
            // the money arrived, and a receipt without a date is not a receipt.
            return null;
        }

        $refunds = self::refundsFor($payment);

        return new self(
            number: self::numberFor($payment),
            paymentReference: $payment->reference,
            purpose: $payment->purpose->label(),
            paidAt: $paidAt,
            lines: self::linesFor($payment),
            amount: $payment->amount_minor->jsonSerialize(),
            settled: self::settlementFor($payment),
            gateway: (string) $payment->gateway,
            isSandbox: $payment->gateway_mode === 'sandbox',
            gatewayReference: $payment->gateway_reference,
            refunds: $refunds['entries'],
            refundedTotal: $refunds['total'],
        );
    }

    /**
     * The number this receipt is issued under.
     *
     * Derived from the payment's own reference, which is already unique and
     * never changes — so the same payment produces the same receipt number
     * today, next year, and after any restore. A counter table would be a
     * second thing to keep consistent, and a receipt whose number moved would
     * be worse than useless to whoever filed it.
     */
    public static function numberFor(Payment $payment): string
    {
        return 'RCP-'.$payment->reference;
    }

    /**
     * What the payment was for, component by component (§5.1).
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function linesFor(Payment $payment): array
    {
        return $payment->allocations
            ->map(fn (PaymentAllocation $allocation) => [
                'type' => $allocation->type->value,
                'label' => $allocation->description ?? $allocation->type->label(),
                'amount' => $allocation->amount_minor->jsonSerialize(),
                'is_deduction' => $allocation->type->isDeduction(),
            ])
            ->all();
    }

    /**
     * The provider's own settlement figures, when they differ from the charge.
     *
     * @return array<string, mixed>|null
     */
    protected static function settlementFor(Payment $payment): ?array
    {
        $currency = $payment->settled_currency_code;
        $minor = $payment->settled_amount_minor;

        if ($currency === null || $minor === null) {
            return null;
        }

        // Same money, said twice. Nothing to show.
        if ($currency === $payment->currency_code && (int) $minor === $payment->amount_minor->minorUnits) {
            return null;
        }

        return Money::of((int) $minor, Currency::from($currency))->jsonSerialize();
    }

    /**
     * Refunds that actually went back, and what they came to.
     *
     * Only processed ones. An approved refund nobody has sent is not money the
     * account holder has received, and a receipt claiming otherwise would be
     * wrong in the direction that matters.
     *
     * @return array{entries: array<int, array<string, mixed>>, total: array<string, mixed>|null}
     */
    protected static function refundsFor(Payment $payment): array
    {
        $refunds = RefundRequest::query()
            ->where('payment_id', $payment->id)
            ->where('status', RefundStatus::Processed)
            ->orderBy('processed_at')
            ->get();

        if ($refunds->isEmpty()) {
            return ['entries' => [], 'total' => null];
        }

        $total = $refunds->reduce(
            fn ($carry, RefundRequest $refund) => $carry === null
                ? $refund->amount_minor
                : $carry->plus($refund->amount_minor),
        );

        return [
            'entries' => $refunds
                ->map(fn (RefundRequest $refund) => [
                    /*
                     * The amount and the date, and nothing else. Not the
                     * reason, not the decision note, not the internal note —
                     * those are written by staff for staff (§7.3).
                     */
                    'amount' => $refund->amount_minor->jsonSerialize(),
                    'processed_at' => $refund->processed_at?->toIso8601String(),
                ])
                ->all(),
            'total' => $total?->jsonSerialize(),
        ];
    }

    protected static function isRefunded(Payment $payment): bool
    {
        /*
         * A refunded payment still gets a receipt. The money did arrive, the
         * account holder's records need to show that it did, and the refund is
         * shown beside it rather than replacing it.
         */
        return $payment->completed_at !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'payment_reference' => $this->paymentReference,
            'purpose' => $this->purpose,
            'paid_at' => $this->paidAt->toIso8601String(),
            'lines' => $this->lines,
            'amount' => $this->amount,
            'settled' => $this->settled,
            'gateway' => $this->gateway,
            'is_sandbox' => $this->isSandbox,
            'gateway_reference' => $this->gatewayReference,
            'refunds' => $this->refunds,
            'refunded_total' => $this->refundedTotal,
        ];
    }
}
