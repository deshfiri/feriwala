<?php

namespace App\Domain\ExternalStore\Actions;

use App\Domain\ExternalStore\Enums\ExternalOrderEventSource;
use App\Domain\ExternalStore\Enums\ExternalOrderEventType;
use App\Domain\ExternalStore\Exceptions\ExternalOrderNotRecorded;
use App\Domain\ExternalStore\Models\ExternalCustomer;
use App\Domain\ExternalStore\Models\ExternalOrder;
use App\Domain\ExternalStore\Models\ExternalOrderEvent;
use App\Domain\ExternalStore\Models\ExternalStore;
use App\Integrations\Store\Data\StoreOrder;
use App\Integrations\Store\Data\StoreOrderLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Write down one order exactly as the store reported it (D29). Idempotent.
 *
 * The same order arrives many times — every sync pass, every notification, a
 * replay — and each arrival must leave exactly one order behind. Found by the
 * store's own id, under a row lock; created only when absent, with the unique
 * index settling two arrivals racing to create it.
 *
 *   - **New order.** Saved with its lines, its customer (within this store),
 *     and one `imported` event carrying the status it arrived in, dated when
 *     the store says it was placed. Nothing is invented about how it got there.
 *   - **Known order, newer copy.** What the store changed is applied and each
 *     change to its status or payment status is recorded as an event, dated by
 *     the store's own "updated at".
 *   - **Known order, same or older copy.** Left exactly as it is: an older
 *     notification arriving late never rolls an order back.
 *
 *   - **No such order, and too little to create one** — a webhook short of the
 *     store's id, an amount or the items. Refused as incomplete, so the caller
 *     can let the pull API bring it in whole. Nothing is filled in.
 *   - **An id and an order number for two different orders** — refused, never
 *     merged.
 *
 * The lines are the store's snapshot at import and are not rewritten.
 * Nothing here touches Feriwala's own orders, payments, stock or wallet.
 */
class RecordExternalOrder
{
    public function __construct(
        protected DatabaseManager $database,
    ) {}

    /**
     * @return 'created'|'updated'|'unchanged'
     *
     * @throws ExternalOrderNotRecorded when the copy cannot be recorded safely
     */
    public function handle(ExternalStore $store, StoreOrder $incoming, ?string $eventId = null): string
    {
        try {
            return $this->database->transaction(fn () => $this->apply($store, $incoming, $eventId));
        } catch (UniqueConstraintViolationException) {
            // Another worker created it a moment ago. Apply this copy to it.
            return $this->database->transaction(fn () => $this->apply($store, $incoming, $eventId));
        }
    }

    /**
     * @return 'created'|'updated'|'unchanged'
     *
     * @throws ExternalOrderNotRecorded
     */
    protected function apply(ExternalStore $store, StoreOrder $incoming, ?string $eventId): string
    {
        $existing = $this->find($store, $incoming);
        $now = CarbonImmutable::now();

        if ($existing === null) {
            $missing = $incoming->missingForCreation();

            if ($missing !== null) {
                throw ExternalOrderNotRecorded::incomplete($missing);
            }

            $this->create($store, $incoming, $eventId, $now);

            return 'created';
        }

        if ($incoming->updatedAt !== null
            && $existing->source_updated_at !== null
            && $incoming->updatedAt->lessThanOrEqualTo($existing->source_updated_at)) {
            return 'unchanged';
        }

        $changes = [];

        if ($incoming->status !== null && $existing->source_status !== $incoming->status) {
            $changes[] = [ExternalOrderEventType::StatusChanged, $existing->source_status, $incoming->status];
        }

        if ($incoming->paymentStatus !== null && $existing->payment_status !== $incoming->paymentStatus) {
            $changes[] = [ExternalOrderEventType::PaymentStatusChanged, $existing->payment_status, $incoming->paymentStatus];
        }

        $existing->fill($this->keepingWhatWasNotSent($existing, $this->orderAttributes($incoming)));

        if (! $existing->isDirty()) {
            return 'unchanged';
        }

        $existing->forceFill(['last_synced_at' => $now])->save();

        foreach ($changes as [$type, $previous, $new]) {
            $this->event($existing, $type, $previous, $new, $incoming->updatedAt, $eventId, $now);
        }

        return 'updated';
    }

    /**
     * The order this copy is about, within this store only, locked.
     *
     * By the store's own id when the copy has one. Otherwise by order number,
     * which the store keeps unique (as the OMS that integrates with it relies
     * on) and which this table holds unique per store. When both are given
     * they must agree: an id and a number that point at different orders, or a
     * number already taken by a different id, are refused rather than merged.
     *
     * @throws ExternalOrderNotRecorded
     */
    protected function find(ExternalStore $store, StoreOrder $incoming): ?ExternalOrder
    {
        $scoped = fn () => ExternalOrder::query()->where('external_store_id', $store->id)->lockForUpdate();

        /** @var ExternalOrder|null $byNumber */
        $byNumber = $scoped()->where('order_number', $incoming->orderNumber)->first();

        if ($incoming->externalId === null) {
            return $byNumber;
        }

        /** @var ExternalOrder|null $byId */
        $byId = $scoped()->where('external_id', $incoming->externalId)->first();

        if ($byNumber !== null && ($byId === null || $byId->id !== $byNumber->id)) {
            throw ExternalOrderNotRecorded::identityConflict();
        }

        return $byId;
    }

    protected function create(ExternalStore $store, StoreOrder $incoming, ?string $eventId, CarbonImmutable $now): void
    {
        // A complete copy always has its placed time (missingForCreation).
        $customer = $this->customer($store, $incoming, $incoming->placedAt ?? $now);

        $order = ExternalOrder::create([
            'business_account_id' => $store->business_account_id,
            'external_store_id' => $store->id,
            'external_customer_id' => $customer?->id,
            'platform' => $store->platform,
            'external_id' => $incoming->externalId,
            ...$this->orderAttributes($incoming),
            'last_synced_at' => $now,
        ]);

        foreach ($incoming->lines ?? [] as $index => $line) {
            /** @var StoreOrderLine $line */
            $order->items()->create([
                'line_number' => $index + 1,
                'external_product_id' => $line->externalProductId,
                'sku' => $line->sku,
                'name' => $line->name,
                'variant_label' => $line->variantLabel,
                'quantity' => $line->quantity,
                'currency_code' => $incoming->currencyCode,
                'unit_price' => $line->unitPrice,
                'line_total' => $line->lineTotal,
            ]);
        }

        $this->event($order, ExternalOrderEventType::Imported, null, $incoming->status, $incoming->placedAt, $eventId, $now);
    }

    /**
     * @return array<string, mixed>
     */
    protected function orderAttributes(StoreOrder $incoming): array
    {
        return [
            'order_number' => $incoming->orderNumber,
            'source_status' => $incoming->status,
            'payment_status' => $incoming->paymentStatus,
            'payment_method' => $incoming->paymentMethod,
            'customer' => [
                'name' => $incoming->customer['name'],
                'phone' => $incoming->customer['phone'],
                'email' => $incoming->customer['email'],
            ],
            'shipping_address' => $incoming->shippingAddress,
            'billing_address' => $incoming->billingAddress,
            'currency_code' => $incoming->currencyCode,
            'subtotal' => $incoming->subtotal,
            'discount' => $incoming->discount,
            'coupon_code' => $incoming->couponCode,
            'shipping_total' => $incoming->shippingTotal,
            'tax' => $incoming->tax,
            'total' => $incoming->total,
            'paid_total' => $incoming->paidTotal,
            'note' => $incoming->note,
            'cancel_reason' => $incoming->cancelReason,
            'placed_at' => $incoming->placedAt,
            'source_updated_at' => $incoming->updatedAt,
        ];
    }

    /**
     * A partial copy leaves alone what it does not mention.
     *
     * A store's "order updated" notification often carries only what changed.
     * Anything it did not send — a status, an amount, an address, a note — is
     * not something it cleared, so the value already recorded stays. No amount
     * is ever replaced by a zero because the store left it out.
     *
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    protected function keepingWhatWasNotSent(ExternalOrder $existing, array $incoming): array
    {
        foreach ([
            'source_status', 'payment_status', 'payment_method', 'shipping_address', 'billing_address',
            'subtotal', 'discount', 'coupon_code', 'shipping_total', 'tax', 'total', 'paid_total',
            'note', 'cancel_reason', 'placed_at', 'source_updated_at',
        ] as $key) {
            if ($incoming[$key] === null) {
                unset($incoming[$key]);
            }
        }

        $incoming['customer'] = [
            'name' => $incoming['customer']['name'] ?? $existing->customer['name'] ?? null,
            'phone' => $incoming['customer']['phone'] ?? $existing->customer['phone'] ?? null,
            'email' => $incoming['customer']['email'] ?? $existing->customer['email'] ?? null,
        ];

        return $incoming;
    }

    /**
     * The customer within this store: the store's own id when it gave one,
     * then the phone number, then the email. Never matched across stores, and
     * never on a name alone.
     */
    protected function customer(ExternalStore $store, StoreOrder $incoming, CarbonImmutable $placedAt): ?ExternalCustomer
    {
        $details = $incoming->customer;
        $phone = $details['phone'] === null ? null : preg_replace('/\D+/', '', $details['phone']);
        $email = $details['email'] === null ? null : mb_strtolower($details['email']);

        $key = match (true) {
            $details['external_id'] !== null => 'id:'.$details['external_id'],
            $phone !== null && $phone !== '' => 'phone:'.$phone,
            $email !== null => 'email:'.$email,
            default => null,
        };

        if ($key === null) {
            return null;
        }

        /** @var ExternalCustomer $customer */
        $customer = ExternalCustomer::query()->firstOrCreate(
            ['external_store_id' => $store->id, 'identity_key' => $key],
            [
                'business_account_id' => $store->business_account_id,
                'external_id' => $details['external_id'],
                'first_order_at' => $placedAt,
            ],
        );

        $customer->fill([
            'name' => $details['name'] ?? $customer->name,
            'phone' => $details['phone'] ?? $customer->phone,
            'email' => $details['email'] ?? $customer->email,
            'first_order_at' => $customer->first_order_at === null || $placedAt->lessThan($customer->first_order_at)
                ? $placedAt
                : $customer->first_order_at,
            'last_order_at' => $customer->last_order_at === null || $placedAt->greaterThan($customer->last_order_at)
                ? $placedAt
                : $customer->last_order_at,
        ])->save();

        return $customer;
    }

    protected function event(
        ExternalOrder $order,
        ExternalOrderEventType $type,
        ?string $previous,
        ?string $new,
        ?CarbonImmutable $occurredAt,
        ?string $eventId,
        CarbonImmutable $now,
    ): void {
        ExternalOrderEvent::create([
            'external_order_id' => $order->id,
            'type' => $type,
            'source' => ExternalOrderEventSource::ExternalStore,
            'previous_value' => $previous,
            'new_value' => $new,
            'external_event_id' => $eventId,
            'occurred_at' => $occurredAt,
            'recorded_at' => $now,
        ]);
    }
}
