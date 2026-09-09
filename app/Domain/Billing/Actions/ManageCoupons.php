<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\Enums\CouponScope;
use App\Domain\Billing\Enums\DiscountType;
use App\Domain\Billing\Models\Coupon;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Writing and withdrawing coupons (§9).
 *
 * Withdrawing closes a coupon rather than deleting it. Redemptions reference it,
 * an invoice names the discount it produced, and a promotion that vanishes takes
 * the explanation of every discounted total with it (§36.2).
 *
 * A percentage is stored in **basis points** so a rate can be exact — 12.5% is
 * 1250 and needs no decimal anywhere near money.
 */
class ManageCoupons
{
    /** 100% in basis points. A coupon cannot be worth more than the thing. */
    public const MAXIMUM_BASIS_POINTS = 10000;

    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function create(
        User $actor,
        string $code,
        string $name,
        DiscountType $type,
        int $value,
        CouponScope $appliesTo,
        Currency $currency,
        CarbonImmutable $effectiveFrom,
        ?CarbonImmutable $effectiveUntil = null,
        ?int $packageId = null,
        ?Money $minimumSpend = null,
        ?Money $maximumDiscount = null,
        ?int $usageLimit = null,
        ?int $perAccountLimit = 1,
    ): Coupon {
        $code = mb_strtoupper(trim($code));

        if ($code === '') {
            throw new InvalidArgumentException('A coupon needs a code.');
        }

        if ($value <= 0) {
            throw new InvalidArgumentException('A coupon must be worth something.');
        }

        if ($type === DiscountType::Percentage && $value > self::MAXIMUM_BASIS_POINTS) {
            throw new InvalidArgumentException('A percentage coupon cannot exceed 100%.');
        }

        if ($effectiveUntil !== null && ! $effectiveUntil->isAfter($effectiveFrom)) {
            throw new InvalidArgumentException('A coupon must end after it begins.');
        }

        if (Coupon::query()->withCode($code)->exists()) {
            throw new InvalidArgumentException('That code is already in use.');
        }

        return $this->database->transaction(function () use (
            $actor, $code, $name, $type, $value, $appliesTo, $currency,
            $effectiveFrom, $effectiveUntil, $packageId,
            $minimumSpend, $maximumDiscount, $usageLimit, $perAccountLimit
        ) {
            $coupon = Coupon::create([
                'code' => $code,
                'name' => $name,
                'discount_type' => $type,
                'value' => $value,
                'currency_code' => $currency->value,
                'applies_to' => $appliesTo,
                'package_id' => $packageId,
                'minimum_spend_minor' => $minimumSpend,
                'maximum_discount_minor' => $maximumDiscount,
                'usage_limit' => $usageLimit,
                'per_account_limit' => $perAccountLimit,
                'effective_from' => $effectiveFrom,
                'effective_until' => $effectiveUntil,
                'is_active' => true,
                'created_by' => $actor->id,
            ]);

            $this->audit->handle(new AuditEntry(
                action: 'billing.coupon_created',
                actorId: $actor->id,
                auditableType: Coupon::class,
                auditableId: $coupon->id,
                after: [
                    'code' => $code,
                    'discount_type' => $type->value,
                    'value' => $value,
                    'applies_to' => $appliesTo->value,
                    'usage_limit' => $usageLimit,
                    'effective_from' => $effectiveFrom->toIso8601String(),
                    'effective_until' => $effectiveUntil?->toIso8601String(),
                ],
                module: 'payment',
                // Revenue given away is what a reconciliation goes looking for.
                isSensitive: true,
            ));

            return $coupon;
        });
    }

    /**
     * Withdraw a coupon from now, leaving what it discounted intact.
     */
    public function withdraw(User $actor, Coupon $coupon): Coupon
    {
        return $this->database->transaction(function () use ($actor, $coupon) {
            /** @var Coupon $locked */
            $locked = Coupon::query()->lockForUpdate()->findOrFail($coupon->id);

            if (! $locked->is_active) {
                return $locked;
            }

            $locked->forceFill([
                'is_active' => false,
                'effective_until' => $locked->effective_until ?? now(),
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: 'billing.coupon_withdrawn',
                actorId: $actor->id,
                auditableType: Coupon::class,
                auditableId: $locked->id,
                before: ['is_active' => true],
                after: ['is_active' => false],
                module: 'payment',
                isSensitive: true,
            ));

            $coupon->setRawAttributes($locked->getAttributes(), sync: true);

            return $locked;
        });
    }
}
