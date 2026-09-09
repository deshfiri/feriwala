<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Actions\ExpireUnpaidPayments;
use App\Domain\Billing\Actions\ReserveCoupon;
use App\Domain\Billing\Enums\CouponScope;
use App\Domain\Billing\Enums\DiscountType;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Enums\RedemptionStatus;
use App\Domain\Billing\Models\Coupon;
use App\Domain\Billing\Models\CouponRedemption;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\PaymentDeadline;
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
 * The §9 payment deadline (P1-48).
 *
 * A checkout that ran out is closed, and gives back what it was holding. Money
 * that arrived is never closed by anything here — the status map does not allow
 * it, which is stronger than a query that remembers to exclude it.
 */

function deadlineTestSetting(?int $hours): void
{
    $settings = app(SettingsRepository::class);

    $settings->define(PaymentDeadline::HOURS, 'billing', SettingType::Integer, $hours ?? 0);
    $settings->set(PaymentDeadline::HOURS, $hours ?? 0);
}

function deadlineTestPayment(
    BusinessAccount $account,
    PaymentStatus $status = PaymentStatus::Draft,
    ?string $expiresAt = '-1 hour',
): Payment {
    return Payment::create([
        'business_account_id' => $account->id,
        'purpose' => PaymentPurpose::Activation,
        'status' => $status,
        'amount_minor' => 600000,
        'currency_code' => 'BDT',
        'expires_at' => $expiresAt === null ? null : now()->modify($expiresAt),
    ]);
}

function deadlineTestCoupon(): Coupon
{
    return Coupon::create([
        'code' => 'HOLD'.Str::upper(Str::random(6)),
        'name' => 'Launch promotion',
        'discount_type' => DiscountType::Percentage,
        'value' => 1000,
        'currency_code' => 'BDT',
        'applies_to' => CouponScope::Fees,
        'usage_limit' => 1,
        'per_account_limit' => 1,
        'effective_from' => now()->subDay(),
        'is_active' => true,
    ]);
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

describe('the configured window', function () {
    it('gives a checkout no deadline until an administrator sets one', function () {
        /*
         * Opt-in, like the KYC deadline. Cancelling real checkouts on a window
         * nobody chose is not a safe default for a fresh installation.
         */
        $this->actingAs($this->applicant)->post(route('packages.select', $this->package));

        Http::fake(['*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://pay.test/go'])]);
        $this->actingAs($this->applicant)->post(route('checkout.pay'), ['gateway' => 'sslcommerz']);

        expect(Payment::query()->firstOrFail()->expires_at)->toBeNull();
    });

    it('stamps the deadline onto the payment when one is configured', function () {
        deadlineTestSetting(48);

        $this->actingAs($this->applicant)->post(route('packages.select', $this->package));

        Http::fake(['*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://pay.test/go'])]);
        $this->actingAs($this->applicant)->post(route('checkout.pay'), ['gateway' => 'sslcommerz']);

        $payment = Payment::query()->firstOrFail();

        expect(now()->diffInHours($payment->expires_at))->toBeGreaterThanOrEqual(47)
            ->and($payment->isOverdue())->toBeFalse();
    });

    it('does not move a deadline already given', function () {
        // Somebody told they have until Friday still has until Friday after the
        // window is shortened on Wednesday.
        deadlineTestSetting(48);
        $payment = deadlineTestPayment($this->account, expiresAt: '+48 hours');
        $original = $payment->expires_at;

        deadlineTestSetting(1);

        expect($payment->refresh()->expires_at?->toIso8601String())
            ->toBe($original?->toIso8601String());
    });

    it('bounds a value somebody mistyped', function () {
        deadlineTestSetting(100000);

        expect(app(PaymentDeadline::class)->hours())->toBe(PaymentDeadline::MAXIMUM_HOURS);
    });
});

describe('the sweep', function () {
    it('closes a checkout that ran out', function () {
        $payment = deadlineTestPayment($this->account);

        expect(app(ExpireUnpaidPayments::class)->handle())->toBe(1)
            ->and($payment->refresh()->status)->toBe(PaymentStatus::Cancelled)
            ->and($payment->cancelled_at)->not->toBeNull();
    });

    it('leaves one that is still inside its window', function () {
        $payment = deadlineTestPayment($this->account, expiresAt: '+2 hours');

        expect(app(ExpireUnpaidPayments::class)->handle())->toBe(0)
            ->and($payment->refresh()->status)->toBe(PaymentStatus::Draft);
    });

    it('leaves one with no deadline at all', function () {
        $payment = deadlineTestPayment($this->account, expiresAt: null);

        app(ExpireUnpaidPayments::class)->handle();

        expect($payment->refresh()->status)->toBe(PaymentStatus::Draft);
    });

    it('never closes a payment that settled', function () {
        /*
         * Enforced by the status map rather than by the query: `Paid` has no
         * move to `Cancelled`, so money that arrived cannot be swept away
         * however this is called.
         */
        $payment = deadlineTestPayment($this->account, PaymentStatus::Paid);

        expect(app(ExpireUnpaidPayments::class)->expire($payment))->toBeFalse()
            ->and($payment->refresh()->status)->toBe(PaymentStatus::Paid);
    });

    it('never closes a payment the gateway is still working on', function () {
        // Pending is money in flight — a bank transfer or a risk review that a
        // gateway will still confirm. Cancelling it would abandon it.
        $payment = deadlineTestPayment($this->account, PaymentStatus::Pending);

        expect(app(ExpireUnpaidPayments::class)->handle())->toBe(0)
            ->and($payment->refresh()->status)->toBe(PaymentStatus::Pending);
    });

    it('closes an initiated checkout the applicant walked away from', function () {
        $payment = deadlineTestPayment($this->account, PaymentStatus::Initiated);

        app(ExpireUnpaidPayments::class)->handle();

        expect($payment->refresh()->status)->toBe(PaymentStatus::Cancelled);
    });

    it('does nothing the second time it runs', function () {
        // Idempotent by transition, not by a marker column that could disagree
        // with the status.
        deadlineTestPayment($this->account);
        $sweep = app(ExpireUnpaidPayments::class);

        expect($sweep->handle())->toBe(1)
            ->and($sweep->handle())->toBe(0);
    });
});

describe('what an expired checkout gives back', function () {
    it('releases the coupon slot it was holding', function () {
        $coupon = deadlineTestCoupon();
        $payment = deadlineTestPayment($this->account);

        app(ReserveCoupon::class)->handle($coupon, $this->account, $payment, Money::of(60000, Currency::BDT));

        app(ExpireUnpaidPayments::class)->handle();

        $redemption = CouponRedemption::query()->firstOrFail();

        expect($redemption->status)->toBe(RedemptionStatus::Released)
            ->and($coupon->refresh()->redeemed_count)->toBe(0);
    });

    it('releases only its own hold, not somebody else\'s', function () {
        // The slot another account is holding on the same coupon is not this
        // checkout's to give back.
        $coupon = deadlineTestCoupon();
        $coupon->forceFill(['usage_limit' => 5, 'per_account_limit' => 1])->save();

        $other = testBusinessAccount(AccountStatus::PaymentPending);
        $live = deadlineTestPayment($other, expiresAt: '+2 hours');
        app(ReserveCoupon::class)->handle($coupon, $other, $live, Money::of(60000, Currency::BDT));

        $overdue = deadlineTestPayment($this->account);
        app(ReserveCoupon::class)->handle($coupon->refresh(), $this->account, $overdue, Money::of(60000, Currency::BDT));

        app(ExpireUnpaidPayments::class)->handle();

        expect(CouponRedemption::query()->where('payment_id', $live->id)->firstOrFail()->status)
            ->toBe(RedemptionStatus::Reserved)
            ->and(CouponRedemption::query()->where('payment_id', $overdue->id)->firstOrFail()->status)
            ->toBe(RedemptionStatus::Released)
            ->and($coupon->refresh()->redeemed_count)->toBe(1);
    });

    it('gives one slot back per expiry, however often the sweep runs', function () {
        $coupon = deadlineTestCoupon();
        $payment = deadlineTestPayment($this->account);

        app(ReserveCoupon::class)->handle($coupon, $this->account, $payment, Money::of(60000, Currency::BDT));

        $sweep = app(ExpireUnpaidPayments::class);
        $sweep->handle();
        $sweep->handle();

        expect($coupon->refresh()->redeemed_count)->toBe(0);
    });

    it('leaves the package selection alone', function () {
        // Missing a deadline costs somebody their checkout, not their place in
        // the funnel.
        deadlineTestSetting(1);
        $this->actingAs($this->applicant)->post(route('packages.select', $this->package));

        Http::fake(['*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://pay.test/go'])]);
        $this->actingAs($this->applicant)->post(route('checkout.pay'), ['gateway' => 'sslcommerz']);

        Payment::query()->update(['expires_at' => now()->subHour()]);
        app(ExpireUnpaidPayments::class)->handle();

        $this->actingAs($this->applicant)
            ->get(route('checkout.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('onboarding/checkout'));
    });
});

describe('the applicant checkout', function () {
    beforeEach(function () {
        $this->actingAs($this->applicant)->post(route('packages.select', $this->package));

        Http::fake(['*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://pay.test/go'])]);
    });

    it('says nothing about a deadline when there is none', function () {
        $this->actingAs($this->applicant)
            ->get(route('checkout.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('deadline.hours', null)
                ->where('deadline.expires_at', null)
                ->where('deadline.expired', false),
            );
    });

    it('shows when a started checkout has to be paid', function () {
        deadlineTestSetting(48);

        $this->actingAs($this->applicant)->post(route('checkout.pay'), ['gateway' => 'sslcommerz']);

        $this->actingAs($this->applicant)
            ->get(route('checkout.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('deadline.hours', 48)
                ->whereNot('deadline.expires_at', null)
                ->where('deadline.expired', false),
            );
    });

    it('says plainly that the last attempt expired', function () {
        deadlineTestSetting(1);

        $this->actingAs($this->applicant)->post(route('checkout.pay'), ['gateway' => 'sslcommerz']);
        Payment::query()->update(['expires_at' => now()->subHour()]);

        $this->actingAs($this->applicant)
            ->get(route('checkout.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('deadline.expired', true)
                ->where('deadline.expires_at', null),
            );

        // Closed on the way in, so the page never offers to pay a dead checkout.
        expect(Payment::query()->firstOrFail()->status)->toBe(PaymentStatus::Cancelled);
    });

    it('starts a new attempt rather than reviving the expired one', function () {
        /*
         * The idempotency key is released with the attempt it belonged to.
         * Keeping it would bind the retry to a cancelled row — and to a total
         * that may no longer be the price.
         */
        deadlineTestSetting(1);

        $this->actingAs($this->applicant)->post(route('checkout.pay'), ['gateway' => 'sslcommerz']);
        Payment::query()->update(['expires_at' => now()->subHour()]);

        $this->actingAs($this->applicant)->post(route('checkout.pay'), ['gateway' => 'sslcommerz']);

        expect(Payment::query()->count())->toBe(2)
            ->and(Payment::query()->where('status', PaymentStatus::Cancelled)->count())->toBe(1)
            ->and(Payment::query()->where('status', PaymentStatus::Initiated)->count())->toBe(1);
    });
});

describe('configuring the deadline', function () {
    beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->manager = testPlatformStaff(PlatformRole::PaymentManager);
    });

    it('shows the current window on the billing screen', function () {
        deadlineTestSetting(48);

        $this->actingAs($this->manager)
            ->get(route('admin.billing.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('payment_deadline.hours', 48));
    });

    it('sets it', function () {
        $this->actingAs($this->manager)
            ->put(route('admin.billing.payment-deadline'), ['hours' => 24])
            ->assertRedirect();

        expect(app(PaymentDeadline::class)->hours())->toBe(24);
    });

    it('turns deadlines off again at zero', function () {
        deadlineTestSetting(24);

        $this->actingAs($this->manager)
            ->put(route('admin.billing.payment-deadline'), ['hours' => 0]);

        expect(app(PaymentDeadline::class)->isEnabled())->toBeFalse();
    });

    it('refuses a window longer than a month', function () {
        $this->actingAs($this->manager)
            ->put(route('admin.billing.payment-deadline'), ['hours' => 10000])
            ->assertSessionHasErrors('hours');
    });

    it('is closed to somebody without the payment permission', function () {
        $packageManager = testPlatformStaff(PlatformRole::PackageManager);

        $this->actingAs($packageManager)
            ->put(route('admin.billing.payment-deadline'), ['hours' => 24])
            ->assertForbidden();

        expect(app(PaymentDeadline::class)->isEnabled())->toBeFalse();
    });
});
