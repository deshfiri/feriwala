<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Actions\ReserveCoupon;
use App\Domain\Billing\Actions\SettleCouponRedemption;
use App\Domain\Billing\CouponValidator;
use App\Domain\Billing\Enums\CouponScope;
use App\Domain\Billing\Enums\DiscountType;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Enums\RedemptionStatus;
use App\Domain\Billing\Models\Coupon;
use App\Domain\Billing\Models\CouponRedemption;
use App\Domain\Billing\Models\Payment;
use App\Domain\Package\Models\Package;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Coupons and promotional discounts (P1-46, §9).
 *
 * A code is held when somebody commits to paying, used when the money arrives,
 * and given back if it never does. Looking at a checkout page must not spend it.
 */

function couponTestCoupon(array $overrides = []): Coupon
{
    return Coupon::create(array_merge([
        'code' => 'SAVE'.Str::upper(Str::random(6)),
        'name' => 'Launch promotion',
        'discount_type' => DiscountType::Percentage,
        'value' => 1000,
        'currency_code' => 'BDT',
        'applies_to' => CouponScope::Fees,
        'per_account_limit' => 1,
        'effective_from' => now()->subDay(),
        'is_active' => true,
    ], $overrides));
}

function couponTestPayment(BusinessAccount $account): Payment
{
    return Payment::create([
        'business_account_id' => $account->id,
        'purpose' => PaymentPurpose::Activation,
        'status' => PaymentStatus::Draft,
        'amount_minor' => 540000,
        'currency_code' => 'BDT',
    ]);
}

/**
 * The undiscounted fees a coupon is worked out against: 1,000 + 5,000 taka.
 *
 * @return array<int, Money>
 */
function couponTestFees(): array
{
    return [Money::of(100000, Currency::BDT), Money::of(500000, Currency::BDT)];
}

beforeEach(function () {
    $settings = app(SettingsRepository::class);
    $settings->define('billing.registration_fee', 'billing', SettingType::Money, 100000);
    $settings->define('billing.gateway_charge_percent', 'billing', SettingType::Decimal, '0');
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

    $this->account = testBusinessAccount(AccountStatus::PackageSelectionPending);
    $this->applicant = $this->account->owner;

    $this->package = Package::create([
        'name' => 'Growth',
        'slug' => 'growth',
        'fee_minor' => 500000,
        'validity_days' => 365,
    ]);
});

describe('what a coupon is worth', function () {
    it('works the discount out against the fees its scope names', function () {
        // §9 keeps the two fees separate, so a coupon for one of them is worked
        // out against that one alone — otherwise "20% off the package fee"
        // would quietly discount the registration fee too.
        $coupon = couponTestCoupon(['applies_to' => CouponScope::PackageFee, 'value' => 2000]);

        $outcome = app(CouponValidator::class)->validate(
            $coupon->code,
            $this->account,
            $this->package,
            ...couponTestFees(),
        );

        expect($outcome->isAccepted)->toBeTrue()
            // 20% of the package fee alone, not of the 600,000 total.
            ->and($outcome->discount?->minorUnits)->toBe(100000);
    });

    it('rounds a percentage down', function () {
        // Never a unit more generous than the rate says.
        $coupon = couponTestCoupon(['value' => 1234, 'applies_to' => CouponScope::PackageFee]);

        $outcome = app(CouponValidator::class)->validate(
            $coupon->code,
            $this->account,
            $this->package,
            Money::of(0, Currency::BDT),
            Money::of(999, Currency::BDT),
        );

        // 999 × 12.34% = 123.28…
        expect($outcome->discount?->minorUnits)->toBe(123);
    });

    it('takes a fixed amount off as given', function () {
        $coupon = couponTestCoupon([
            'discount_type' => DiscountType::Fixed,
            'value' => 75000,
        ]);

        $outcome = app(CouponValidator::class)
            ->validate($coupon->code, $this->account, $this->package, ...couponTestFees());

        expect($outcome->discount?->minorUnits)->toBe(75000);
    });

    it('caps a percentage at the configured maximum', function () {
        $coupon = couponTestCoupon(['value' => 5000, 'maximum_discount_minor' => 50000]);

        $outcome = app(CouponValidator::class)
            ->validate($coupon->code, $this->account, $this->package, ...couponTestFees());

        expect($outcome->discount?->minorUnits)->toBe(50000);
    });

    it('never discounts more than the base', function () {
        // A coupon cannot turn a sale into a payout.
        $coupon = couponTestCoupon(['discount_type' => DiscountType::Fixed, 'value' => 9000000]);

        $outcome = app(CouponValidator::class)
            ->validate($coupon->code, $this->account, $this->package, ...couponTestFees());

        expect($outcome->discount?->minorUnits)->toBe(600000);
    });

    it('is case-insensitive about the code somebody typed', function () {
        // A code read off a poster arrives with whatever capitals the reader used.
        $coupon = couponTestCoupon(['code' => 'LAUNCH25']);

        $outcome = app(CouponValidator::class)
            ->validate('  launch25 ', $this->account, $this->package, ...couponTestFees());

        expect($outcome->isAccepted)->toBeTrue()
            ->and($outcome->coupon?->id)->toBe($coupon->id);
    });
});

describe('refusing a code, and saying why', function () {
    it('names the cause rather than saying "not valid"', function () {
        /*
         * "That code ended on 30 June" and "that code is for a different
         * package" are different problems with different next steps.
         */
        $validator = app(CouponValidator::class);

        $cases = [
            'billing.coupons.refused.unknown' => 'NOTACODE',
            'billing.coupons.refused.expired' => couponTestCoupon([
                'effective_from' => now()->subDays(30),
                'effective_until' => now()->subDay(),
            ])->code,
            'billing.coupons.refused.not_started' => couponTestCoupon([
                'effective_from' => now()->addDay(),
            ])->code,
            'billing.coupons.refused.other_package' => couponTestCoupon([
                'package_id' => Package::create([
                    'name' => 'Enterprise',
                    'slug' => 'enterprise',
                    'fee_minor' => 900000,
                ])->id,
            ])->code,
            'billing.coupons.refused.minimum_spend' => couponTestCoupon([
                'minimum_spend_minor' => 900000,
            ])->code,
        ];

        foreach ($cases as $expected => $code) {
            $outcome = $validator->validate($code, $this->account, $this->package, ...couponTestFees());

            expect($outcome->isAccepted)->toBeFalse()
                ->and($outcome->reason)->toBe($expected);
        }
    });

    it('refuses a withdrawn coupon', function () {
        $coupon = couponTestCoupon(['is_active' => false]);

        $outcome = app(CouponValidator::class)
            ->validate($coupon->code, $this->account, $this->package, ...couponTestFees());

        expect($outcome->isAccepted)->toBeFalse();
    });

    it('refuses one this account has already used', function () {
        $coupon = couponTestCoupon(['per_account_limit' => 1]);

        CouponRedemption::create([
            'coupon_id' => $coupon->id,
            'business_account_id' => $this->account->id,
            'status' => RedemptionStatus::Redeemed,
            'amount_minor' => 50000,
            'currency_code' => 'BDT',
            'redeemed_at' => now(),
        ]);

        $outcome = app(CouponValidator::class)
            ->validate($coupon->code, $this->account, $this->package, ...couponTestFees());

        expect($outcome->reason)->toBe('billing.coupons.refused.already_used');
    });

    it('refuses one that has been fully used', function () {
        $coupon = couponTestCoupon(['usage_limit' => 2, 'redeemed_count' => 2]);

        $outcome = app(CouponValidator::class)
            ->validate($coupon->code, $this->account, $this->package, ...couponTestFees());

        expect($outcome->reason)->toBe('billing.coupons.refused.exhausted');
    });

    it('does not let a released hold keep an account out', function () {
        // A checkout that was abandoned is not a use, and the applicant has to
        // be able to come back and try again.
        $coupon = couponTestCoupon(['per_account_limit' => 1]);
        $payment = couponTestPayment($this->account);

        app(ReserveCoupon::class)->handle($coupon, $this->account, $payment, Money::of(60000, Currency::BDT));
        app(SettleCouponRedemption::class)->release($payment);

        $outcome = app(CouponValidator::class)
            ->validate($coupon->code, $this->account, $this->package, ...couponTestFees());

        expect($outcome->isAccepted)->toBeTrue();
    });
});

describe('holding and spending a use', function () {
    it('holds rather than redeems when a payment is recorded', function () {
        $coupon = couponTestCoupon(['usage_limit' => 5]);
        $payment = couponTestPayment($this->account);

        $reservation = app(ReserveCoupon::class)->handle(
            $coupon,
            $this->account,
            $payment,
            Money::of(60000, Currency::BDT),
        );

        expect($reservation->status)->toBe(RedemptionStatus::Reserved)
            // A hold occupies a slot, or two checkouts could both spend the last.
            ->and($coupon->refresh()->redeemed_count)->toBe(1);
    });

    it('holds once however many times the form is submitted', function () {
        $coupon = couponTestCoupon();
        $payment = couponTestPayment($this->account);
        $reserve = app(ReserveCoupon::class);

        $first = $reserve->handle($coupon, $this->account, $payment, Money::of(60000, Currency::BDT));
        $second = $reserve->handle($coupon, $this->account, $payment, Money::of(60000, Currency::BDT));

        expect($second->id)->toBe($first->id)
            ->and($coupon->refresh()->redeemed_count)->toBe(1);
    });

    it('refuses to hold the last slot twice', function () {
        // Re-checked inside the lock, not trusted from the render that offered it.
        $coupon = couponTestCoupon(['usage_limit' => 1]);
        $reserve = app(ReserveCoupon::class);

        $reserve->handle($coupon, $this->account, couponTestPayment($this->account), Money::of(60000, Currency::BDT));

        $other = testBusinessAccount(AccountStatus::PaymentPending);

        expect(fn () => $reserve->handle(
            $coupon->refresh(),
            $other,
            couponTestPayment($other),
            Money::of(60000, Currency::BDT),
        ))->toThrow(RuntimeException::class);
    });

    it('refuses a hold on a coupon whose window closed while the page was open', function () {
        $coupon = couponTestCoupon();
        $payment = couponTestPayment($this->account);

        $coupon->forceFill(['is_active' => false])->save();

        expect(fn () => app(ReserveCoupon::class)->handle(
            $coupon,
            $this->account,
            $payment,
            Money::of(60000, Currency::BDT),
        ))->toThrow(RuntimeException::class);
    });

    it('turns the hold into a use when the money arrives', function () {
        $coupon = couponTestCoupon();
        $payment = couponTestPayment($this->account);

        app(ReserveCoupon::class)->handle($coupon, $this->account, $payment, Money::of(60000, Currency::BDT));
        app(SettleCouponRedemption::class)->redeem($payment);

        $redemption = CouponRedemption::query()->firstOrFail();

        expect($redemption->status)->toBe(RedemptionStatus::Redeemed)
            ->and($redemption->redeemed_at)->not->toBeNull()
            ->and($coupon->refresh()->redeemed_count)->toBe(1);
    });

    it('gives the slot back when the checkout never pays', function () {
        $coupon = couponTestCoupon(['usage_limit' => 1]);
        $payment = couponTestPayment($this->account);

        app(ReserveCoupon::class)->handle($coupon, $this->account, $payment, Money::of(60000, Currency::BDT));
        app(SettleCouponRedemption::class)->release($payment);

        $redemption = CouponRedemption::query()->firstOrFail();

        expect($redemption->status)->toBe(RedemptionStatus::Released)
            // Kept, not deleted: "tried in March and did not pay" is worth seeing.
            ->and($redemption->released_at)->not->toBeNull()
            ->and($coupon->refresh()->redeemed_count)->toBe(0);
    });

    it('settles once however many times the gateway says so', function () {
        $coupon = couponTestCoupon(['usage_limit' => 1]);
        $payment = couponTestPayment($this->account);
        $settle = app(SettleCouponRedemption::class);

        app(ReserveCoupon::class)->handle($coupon, $this->account, $payment, Money::of(60000, Currency::BDT));

        $settle->release($payment);
        $settle->release($payment);

        // A slot given back twice would inflate the coupon's remaining uses.
        expect($coupon->refresh()->redeemed_count)->toBe(0);
    });

    it('will not redeem a hold that was already released', function () {
        $coupon = couponTestCoupon();
        $payment = couponTestPayment($this->account);
        $settle = app(SettleCouponRedemption::class);

        app(ReserveCoupon::class)->handle($coupon, $this->account, $payment, Money::of(60000, Currency::BDT));
        $settle->release($payment);
        $settle->redeem($payment);

        expect(CouponRedemption::query()->firstOrFail()->status)
            ->toBe(RedemptionStatus::Released);
    });
});

describe('the checkout', function () {
    beforeEach(function () {
        $this->actingAs($this->applicant)->post(route('packages.select', $this->package));
    });

    it('shows the discount once a code is applied, and holds nothing', function () {
        // §9: a code is not spent because a checkout page was opened.
        $coupon = couponTestCoupon(['value' => 1000]);

        $this->actingAs($this->applicant)
            ->post(route('checkout.coupon'), ['code' => $coupon->code])
            ->assertRedirect();

        $this->actingAs($this->applicant)
            ->get(route('checkout.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('onboarding/checkout')
                ->where('coupon.accepted', true)
                // 10% of the 600,000 in fees.
                ->where('coupon.discount.minor_units', 60000)
                ->where('quote.total.minor_units', 540000),
            );

        expect(CouponRedemption::query()->count())->toBe(0)
            ->and($coupon->refresh()->redeemed_count)->toBe(0);
    });

    it('keeps the discount as its own line', function () {
        /*
         * §9 stores each amount separately for reports, invoices,
         * reconciliation, the ledger, refunds and revenue analysis — a netted
         * total answers none of those.
         */
        $coupon = couponTestCoupon(['value' => 1000]);

        $this->actingAs($this->applicant)->post(route('checkout.coupon'), ['code' => $coupon->code]);

        $this->actingAs($this->applicant)
            ->get(route('checkout.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('quote.lines.0.type', 'registration_fee')
                ->where('quote.lines.1.type', 'package_fee')
                ->where('quote.lines.2.type', 'discount')
                ->where('quote.lines.2.amount.minor_units', 60000)
                ->where('quote.lines.2.is_deduction', true),
            );
    });

    it('tells the applicant why a code was refused', function () {
        couponTestCoupon(['code' => 'GONE', 'effective_until' => now()->subDay()]);

        $this->actingAs($this->applicant)->post(route('checkout.coupon'), ['code' => 'GONE']);

        $this->actingAs($this->applicant)
            ->get(route('checkout.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('coupon.accepted', false)
                ->whereNot('coupon.reason', null)
                // Refused means not applied: the total is the undiscounted one.
                ->where('quote.total.minor_units', 600000),
            );
    });

    it('clears the code when the field is submitted empty', function () {
        $coupon = couponTestCoupon();

        $this->actingAs($this->applicant)->post(route('checkout.coupon'), ['code' => $coupon->code]);
        $this->actingAs($this->applicant)->post(route('checkout.coupon'), ['code' => '']);

        $this->actingAs($this->applicant)
            ->get(route('checkout.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('coupon', null)
                ->where('quote.total.minor_units', 600000),
            );
    });

    it('holds the coupon and charges the discounted amount when the payment is recorded', function () {
        Http::fake([
            '*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://pay.test/go']),
        ]);

        $coupon = couponTestCoupon(['value' => 1000, 'usage_limit' => 1]);

        $this->actingAs($this->applicant)->post(route('checkout.coupon'), ['code' => $coupon->code]);
        $this->actingAs($this->applicant)->post(route('checkout.pay'), ['gateway' => 'sslcommerz']);

        $payment = Payment::query()->firstOrFail();

        expect($payment->amount_minor->minorUnits)->toBe(540000)
            ->and($coupon->refresh()->redeemed_count)->toBe(1);

        $redemption = CouponRedemption::query()->firstOrFail();

        expect($redemption->status)->toBe(RedemptionStatus::Reserved)
            ->and($redemption->payment_id)->toBe($payment->id)
            // Snapshotted: the coupon can be edited afterwards and this still
            // has to reconcile with the allocation it produced.
            ->and($redemption->amount_minor->minorUnits)->toBe(60000);
    });

    it('charges the full amount when the last slot went while the page was open', function () {
        /*
         * The code is revalidated at payment, not honoured from the render that
         * offered it. Charging a discounted total against a coupon somebody else
         * has since taken would be revenue given away twice.
         */
        Http::fake([
            '*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://pay.test/go']),
        ]);

        $coupon = couponTestCoupon(['value' => 1000, 'usage_limit' => 1]);

        $this->actingAs($this->applicant)->post(route('checkout.coupon'), ['code' => $coupon->code]);

        $other = testBusinessAccount(AccountStatus::PaymentPending);
        $taken = couponTestPayment($other);
        app(ReserveCoupon::class)->handle($coupon, $other, $taken, Money::of(60000, Currency::BDT));

        $this->actingAs($this->applicant)->post(route('checkout.pay'), ['gateway' => 'sslcommerz']);

        $payment = Payment::query()->where('business_account_id', $this->account->id)->firstOrFail();

        expect($payment->amount_minor->minorUnits)->toBe(600000)
            // Still the one hold the other account took, and no second.
            ->and($coupon->refresh()->redeemed_count)->toBe(1)
            ->and(CouponRedemption::query()->where('payment_id', $payment->id)->exists())->toBeFalse();
    });
});

describe('managing coupons', function () {
    beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->manager = testPlatformStaff(PlatformRole::PaymentManager);
    });

    it('lists them for somebody with the payment permission', function () {
        couponTestCoupon();

        $this->actingAs($this->manager)
            ->get(route('admin.billing.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing')
                ->has('coupons', 1)
                ->where('coupons.0.is_open', true),
            );
    });

    it('refuses a duplicate code', function () {
        couponTestCoupon(['code' => 'LAUNCH']);

        $this->actingAs($this->manager)
            ->post(route('admin.billing.coupons.store'), [
                'code' => 'launch',
                'name' => 'Another',
                'discount_type' => DiscountType::Percentage->value,
                'value' => 1000,
                'applies_to' => CouponScope::Fees->value,
                'effective_from' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('code');

        expect(Coupon::query()->count())->toBe(1);
    });

    it('refuses a percentage above one hundred', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.billing.coupons.store'), [
                'code' => 'TOOMUCH',
                'name' => 'Too much',
                'discount_type' => DiscountType::Percentage->value,
                'value' => 12000,
                'applies_to' => CouponScope::Fees->value,
                'effective_from' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('code');

        expect(Coupon::query()->count())->toBe(0);
    });

    it('withdraws rather than deletes', function () {
        // Redemptions reference it, and an invoice names the discount it made.
        $coupon = couponTestCoupon();

        $this->actingAs($this->manager)
            ->delete(route('admin.billing.coupons.withdraw', $coupon->public_id))
            ->assertRedirect();

        expect($coupon->refresh()->is_active)->toBeFalse()
            ->and(Coupon::query()->count())->toBe(1);
    });

    it('is closed to somebody without the payment permission', function () {
        // Writing package copy and giving revenue away are different jobs.
        $packageManager = testPlatformStaff(PlatformRole::PackageManager);

        $this->actingAs($packageManager)
            ->post(route('admin.billing.coupons.store'), [
                'code' => 'NOPE',
                'name' => 'Nope',
                'discount_type' => DiscountType::Fixed->value,
                'value' => 1000,
                'applies_to' => CouponScope::Fees->value,
                'effective_from' => now()->toDateString(),
            ])
            ->assertForbidden();

        expect(Coupon::query()->count())->toBe(0);
    });
});
