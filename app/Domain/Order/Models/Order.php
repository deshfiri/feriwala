<?php

namespace App\Domain\Order\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Concerns\HasReference;
use App\Concerns\HasStateMachine;
use App\Concerns\RecordsStatusHistory;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Models\Payment;
use App\Domain\Order\Actions\AnnounceWebsiteOrderStatus;
use App\Domain\Order\Enums\IntendedResaleChannel;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderFulfillmentStatus;
use App\Domain\Order\Enums\OrderNotificationStatus;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCustomer;
use App\Domain\Wholesale\Models\Cart;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\References\ReferencePrefix;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One order, from whichever source it came (§18, P6-1).
 *
 * A record of a sale: what was bought and for how much are snapshots taken when
 * it was placed and are never rewritten — the database refuses it — and an order
 * is never deleted. Its status moves only through `transitionTo()` along
 * {@see OrderStatus}, and the database holds it to the same map.
 *
 * Whether it has been paid is the payment's answer, read through its payment
 * relation; an order does not keep a second one.
 *
 * @property int $id
 * @property string $public_id
 * @property string $reference
 * @property OrderSource $source
 * @property OrderStatus $status
 * @property int $business_account_id
 * @property int|null $placed_by
 * @property int|null $website_id
 * @property int|null $website_customer_id
 * @property string|null $storefront_order_reference
 * @property string|null $storefront_return_url
 * @property int|null $cart_id
 * @property int|null $payment_id
 * @property string|null $idempotency_key
 * @property string|null $checkout_fingerprint
 * @property array<string, string|null> $customer
 * @property array<string, string|null>|null $billing_address
 * @property array<string, string|null>|null $shipping_address
 * @property string $currency_code
 * @property Money $subtotal_minor
 * @property Money $discount_minor
 * @property Money $delivery_minor
 * @property Money $tax_minor
 * @property Money $tax_included_minor
 * @property Money $cod_fee_minor
 * @property Money $total_minor
 * @property string|null $coupon_code
 * @property OrderFulfillmentStatus $fulfillment_status
 * @property OrderCourierStatus $courier_status
 * @property OrderDeliveryStatus $delivery_status
 * @property string|null $customer_note
 * @property IntendedResaleChannel|null $intended_resale_channel
 * @property CarbonImmutable $placed_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property CarbonImmutable|null $held_at
 * @property string|null $hold_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read BusinessAccount $businessAccount
 * @property-read User|null $placedBy
 * @property-read Website|null $website
 * @property-read WebsiteCustomer|null $websiteCustomer
 * @property-read Cart|null $cart
 * @property-read Payment|null $payment
 * @property-read Collection<int, OrderItem> $items
 * @property-read Collection<int, OrderStatusChange> $statusHistory
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasPublicId, HasReference, HasStateMachine, RecordsStatusHistory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => OrderSource::class,
            'status' => OrderStatus::class,
            'fulfillment_status' => OrderFulfillmentStatus::class,
            'courier_status' => OrderCourierStatus::class,
            'delivery_status' => OrderDeliveryStatus::class,
            'intended_resale_channel' => IntendedResaleChannel::class,
            'customer' => 'array',
            'billing_address' => 'array',
            'shipping_address' => 'array',
            'subtotal_minor' => MoneyCast::class,
            'discount_minor' => MoneyCast::class,
            'delivery_minor' => MoneyCast::class,
            'tax_minor' => MoneyCast::class,
            'tax_included_minor' => MoneyCast::class,
            'cod_fee_minor' => MoneyCast::class,
            'total_minor' => MoneyCast::class,
            'placed_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'held_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Domain models live deeper than the factory naming convention expects, so
     * the binding is stated rather than guessed.
     */
    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('An order is a record of a sale and is never deleted.'));
    }

    public function referencePrefix(): ReferencePrefix
    {
        return ReferencePrefix::Order;
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by');
    }

    /**
     * The partner website a website order was placed on (§17).
     *
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * The website's customer who placed it. What the order says about them is
     * its own snapshot; this is the live record, for the shop's customer list.
     *
     * @return BelongsTo<WebsiteCustomer, $this>
     */
    public function websiteCustomer(): BelongsTo
    {
        return $this->belongsTo(WebsiteCustomer::class);
    }

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('line_number');
    }

    /**
     * Every recorded status change, oldest first (§18.3).
     *
     * @return HasMany<OrderStatusChange, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusChange::class)->orderBy('id');
    }

    /**
     * Move the order along the transition map and record why, in one transaction.
     *
     * The caller has already decided the move is allowed for this order and
     * locked it; this refuses a move the map does not have and writes the move
     * and its history together.
     *
     * A website order's storefront is told in the same transaction (contract
     * §7.1): the delivery is written beside the move and sent once it commits,
     * so no move goes unannounced and none is announced that did not happen.
     */
    public function moveTo(
        OrderStatus $to,
        StatusChange $change,
        OrderStatusChangeSource $source,
        OrderNotificationStatus $notification = OrderNotificationStatus::NotRequired,
    ): OrderStatusChange {
        /** @var OrderStatusChange $entry */
        $entry = $this->transitionWithHistory($to, $change, [
            'source' => $source,
            'notification_status' => $notification,
        ]);

        if ($this->source === OrderSource::Website) {
            app(AnnounceWebsiteOrderStatus::class)->handle($this, $entry);
        }

        return $entry;
    }

    /**
     * Record the status an order was created in.
     */
    public function recordPlacement(
        StatusChange $change,
        OrderStatusChangeSource $source,
        OrderNotificationStatus $notification = OrderNotificationStatus::NotRequired,
    ): OrderStatusChange {
        /** @var OrderStatusChange $entry */
        $entry = $this->recordStatusChange(null, $this->status, $change, [
            'source' => $source,
            'notification_status' => $notification,
        ]);

        return $entry;
    }
}
