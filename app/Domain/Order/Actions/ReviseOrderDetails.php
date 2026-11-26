<?php

namespace App\Domain\Order\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Order\Data\OrderDetailsRevision;
use App\Domain\Order\Data\RevisableOrderState;
use App\Domain\Order\Enums\OrderRevisionSection;
use App\Domain\Order\Enums\OrderRevisionStatus;
use App\Domain\Order\Enums\RevisionCourierConsequence;
use App\Domain\Order\Enums\RevisionPaymentConsequence;
use App\Domain\Order\Exceptions\OrderRevisionRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderRevision;
use App\Domain\Order\Queries\OrderRevisionEligibility;
use App\Models\User;
use App\Support\Concurrency\DistributedLock;
use App\Support\Concurrency\Exceptions\LockTimeout;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;

/**
 * Correct who an order is for and where it goes — the customer's name, phone
 * and email, the delivery and billing addresses, the customer's note — through
 * one applied, append-only revision (D38).
 *
 * Nothing here changes what the order costs: the structured delivery area an
 * address is priced from (D37) and the country stay as they were, so the
 * delivery charge, the total and the payment are untouched. Only the fields an
 * order already has may be corrected; none is added, and no identity field
 * (customer id, guest flag, area id) is ever reachable.
 *
 * Under a lock on the order, in one transaction: the order is locked again,
 * held to the revision the staff saw (anything since is `revision_changed`),
 * checked against the state rules, the revision is written with the order
 * before and after, and the order is updated to exactly that — which the
 * database itself verifies. The same request twice applies once.
 */
class ReviseOrderDetails
{
    /** Customer fields a revision may correct, whichever shape the order keeps them in. */
    public const CUSTOMER_FIELDS = ['name', 'contact_name', 'mobile', 'phone', 'email', 'business_name'];

    /** Address fields a revision may correct. The area id and the country are not among them. */
    public const ADDRESS_FIELDS = [
        'contact_name', 'contact_mobile', 'name', 'mobile', 'phone',
        'line1', 'line2', 'line_1', 'line_2', 'detailed_address', 'landmark',
        'area', 'union', 'upazila', 'city', 'district', 'division', 'postcode',
    ];

    public const MINIMUM_REASON = 10;

    public function __construct(
        protected DatabaseManager $database,
        protected DistributedLock $lock,
        protected OrderRevisionEligibility $eligibility,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @throws AuthorizationException when the actor may not change a part they changed
     * @throws OrderRevisionRefused
     */
    public function handle(User $actor, Order $order, OrderDetailsRevision $request): OrderRevision
    {
        if (mb_strlen(trim($request->reason)) < self::MINIMUM_REASON) {
            throw OrderRevisionRefused::because('reason_required', 'reason');
        }

        try {
            return $this->lock->run(
                key: 'order-revision:'.$order->id,
                callback: fn () => $this->apply($actor, $order, $request),
                ttlSeconds: 30,
                waitSeconds: 10,
            );
        } catch (LockTimeout) {
            throw OrderRevisionRefused::busy();
        } catch (UniqueConstraintViolationException) {
            // The same request, racing itself: the one that won is the answer.
            return OrderRevision::query()->where('idempotency_key', $this->key($order, $request))->firstOr(
                fn () => throw OrderRevisionRefused::changed(),
            );
        }
    }

    protected function apply(User $actor, Order $order, OrderDetailsRevision $request): OrderRevision
    {
        return $this->database->transaction(function () use ($actor, $order, $request): OrderRevision {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $existing = OrderRevision::query()->where('idempotency_key', $this->key($locked, $request))->first();

            if ($existing !== null) {
                return $existing;
            }

            if ($locked->revision_number !== $request->baseRevision) {
                throw OrderRevisionRefused::changed();
            }

            $blocker = $this->eligibility->blocker($locked);

            if ($blocker !== null) {
                throw OrderRevisionRefused::because($blocker);
            }

            $before = RevisableOrderState::current($locked);

            $after = RevisableOrderState::with($before, array_filter([
                'customer' => $this->corrected($before['customer'] ?? null, $request->customer, self::CUSTOMER_FIELDS),
                'shipping_address' => $this->corrected($before['shipping_address'] ?? null, $request->shippingAddress, self::ADDRESS_FIELDS),
                'billing_address' => $this->corrected($before['billing_address'] ?? null, $request->billingAddress, self::ADDRESS_FIELDS),
            ], fn (?array $value) => $value !== null));

            $changed = RevisableOrderState::changedColumns($before, $after);
            $noteBefore = $locked->customer_note;
            $noteAfter = $request->noteGiven ? $this->clean($request->customerNote) : $noteBefore;
            $noteChanged = $noteAfter !== $noteBefore;

            if ($changed === [] && ! $noteChanged) {
                throw OrderRevisionRefused::nothingChanged();
            }

            $sections = $this->sections($changed, $noteChanged);
            $this->authorize($actor, $sections);

            $now = CarbonImmutable::now();
            $total = $locked->total;

            $revision = OrderRevision::create([
                'order_id' => $locked->id,
                'revision_number' => $locked->revision_number + 1,
                'status' => OrderRevisionStatus::Applied,
                'sections' => array_map(fn (OrderRevisionSection $section) => $section->value, $sections),
                'requested_by' => $actor->id,
                'applied_by' => $actor->id,
                'reason' => trim($request->reason),
                'internal_note' => $this->clean($request->internalNote),
                'before_snapshot' => ['order' => $before, 'customer_note' => $noteBefore, 'lines' => $this->lines($locked)],
                'requested_snapshot' => $request->toSnapshot(),
                'final_snapshot' => ['order' => $after, 'customer_note' => $noteAfter, 'lines' => $this->lines($locked)],
                'currency_code' => $total->currency->value,
                'total_before' => $total,
                'total_after' => $total,
                'total_difference' => $total->minus($total),
                'stock_difference' => [],
                'payment_consequence' => RevisionPaymentConsequence::None,
                'courier_consequence' => RevisionCourierConsequence::NotBooked,
                'fingerprint' => hash('sha256', json_encode([$after, $noteAfter], JSON_THROW_ON_ERROR)),
                'idempotency_key' => $this->key($locked, $request),
                'requested_at' => $now,
                'applied_at' => $now,
            ]);

            $attributes = RevisableOrderState::attributes($after, $total->currency->value);

            $locked->forceFill([
                ...array_intersect_key($attributes, array_flip($changed)),
                'customer_note' => $noteAfter,
                'revision_number' => $revision->revision_number,
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: 'order.revised',
                actorId: $actor->id,
                auditableType: Order::class,
                auditableId: $locked->id,
                // References and what changed — never the customer's name,
                // phone, email or address themselves.
                before: ['reference' => $locked->reference, 'revision_number' => $revision->revision_number - 1],
                after: [
                    'reference' => $locked->reference,
                    'revision' => $revision->public_id,
                    'revision_number' => $revision->revision_number,
                    'sections' => $revision->sections,
                    'fields' => [...$changed, ...($noteChanged ? ['customer_note'] : [])],
                    'total_difference' => '0.00',
                    'payment_consequence' => $revision->payment_consequence->value,
                    'courier_consequence' => $revision->courier_consequence->value,
                ],
                reason: $revision->reason,
                accountId: $locked->business_account_id,
                module: 'order',
                isSensitive: true,
            ));

            return $revision;
        });
    }

    /**
     * The snapshot with only the allowed fields it already has replaced.
     *
     * @param  array<string, mixed>|null  $current
     * @param  array<string, string|null>  $requested
     * @param  list<string>  $allowed
     * @return array<string, mixed>|null null when the order has no such snapshot
     */
    public function corrected(?array $current, array $requested, array $allowed): ?array
    {
        if ($current === null) {
            return null;
        }

        foreach ($requested as $field => $value) {
            if (in_array($field, $allowed, true) && array_key_exists($field, $current)) {
                $current[$field] = $this->clean($value);
            }
        }

        return $current;
    }

    /**
     * @param  list<string>  $changed
     * @return list<OrderRevisionSection>
     */
    protected function sections(array $changed, bool $noteChanged): array
    {
        $sections = [];

        if (in_array('customer', $changed, true)) {
            $sections[] = OrderRevisionSection::Customer;
        }

        if (array_intersect(['shipping_address', 'billing_address'], $changed) !== []) {
            $sections[] = OrderRevisionSection::Addresses;
        }

        if ($noteChanged) {
            $sections[] = OrderRevisionSection::Note;
        }

        return $sections;
    }

    /**
     * Each part changed needs its own permission; the customer's note belongs
     * with the customer's details.
     *
     * @param  list<OrderRevisionSection>  $sections
     *
     * @throws AuthorizationException
     */
    protected function authorize(User $actor, array $sections): void
    {
        foreach ($sections as $section) {
            $action = $section->permission() ?? PermissionAction::EditCustomer;

            Gate::forUser($actor)->authorize(PermissionCatalogue::name(PermissionModule::Order, $action));
        }
    }

    /**
     * The effective lines as they stand: a revision of the details leaves them
     * exactly so, before and after.
     *
     * @return list<array<string, mixed>>
     */
    protected function lines(Order $order): array
    {
        return $order->items()->get()->map(fn (OrderItem $item) => [
            'id' => $item->public_id,
            'sku' => $item->sku,
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price->toDecimal(),
            'line_total' => $item->line_total->toDecimal(),
        ])->values()->all();
    }

    protected function key(Order $order, OrderDetailsRevision $request): string
    {
        return 'order-revision:'.$order->public_id.':'.$request->idempotencyKey;
    }

    public function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
