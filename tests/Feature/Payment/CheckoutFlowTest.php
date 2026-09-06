<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $settings = app(SettingsRepository::class);
    $settings->define('billing.registration_fee', 'billing', SettingType::Money, 100000);
    $settings->define('billing.gateway_charge_percent', 'billing', SettingType::Decimal, '0');
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

    // The screens are used by a person; the money belongs to their business.
    $this->account = testBusinessAccount(AccountStatus::PackageSelectionPending);
    $this->applicant = $this->account->owner;

    $this->package = Package::create([
        'name' => 'Growth',
        'slug' => 'growth',
        'fee_minor' => 500000,
        'validity_days' => 365,
    ]);
});

describe('choosing a package', function () {
    it('shows the total payable today, not just the package fee', function () {
        // §5.1 charges the registration fee alongside — showing only the
        // package price understates every option.
        $this->actingAs($this->applicant)
            ->get(route('packages.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('onboarding/packages')
                ->where('packages.0.fee.minor_units', 500000)
                ->where('packages.0.activation_total.minor_units', 600000),
            );
    });

    it('records the choice and moves to payment', function () {
        $this->actingAs($this->applicant)
            ->post(route('packages.select', $this->package))
            ->assertRedirect(route('checkout.show'));

        expect($this->account->fresh()->status)->toBe(AccountStatus::PaymentPending)
            ->and(UserPackage::where('business_account_id', $this->account->id)->count())->toBe(1);
    });

    it('replaces an earlier unpaid choice rather than stacking them', function () {
        $other = Package::create(['name' => 'Starter', 'slug' => 'starter', 'fee_minor' => 200000]);

        $this->actingAs($this->applicant)->post(route('packages.select', $this->package));
        $this->actingAs($this->applicant)->post(route('packages.select', $other));

        $pending = UserPackage::where('business_account_id', $this->account->id)
            ->where('status', UserPackageStatus::PendingPayment)
            ->get();

        expect($pending)->toHaveCount(1)
            ->and($pending->first()->package_id)->toBe($other->id);
    });

    it('keeps the superseded choice rather than deleting it', function () {
        // Deleting rows a payment might reference is how orphans happen.
        $other = Package::create(['name' => 'Starter', 'slug' => 'starter', 'fee_minor' => 200000]);

        $this->actingAs($this->applicant)->post(route('packages.select', $this->package));
        $this->actingAs($this->applicant)->post(route('packages.select', $other));

        expect(UserPackage::where('business_account_id', $this->account->id)->count())->toBe(2);
    });

    it('does not offer an unavailable package', function () {
        Package::create([
            'name' => 'Retired',
            'slug' => 'retired',
            'fee_minor' => 100000,
            'is_active' => false,
        ]);

        $this->actingAs($this->applicant)
            ->get(route('packages.index'))
            ->assertInertia(fn (Assert $page) => $page->has('packages', 1));
    });
});

describe('checkout', function () {
    beforeEach(function () {
        $this->actingAs($this->applicant)->post(route('packages.select', $this->package));
    });

    it('itemises every component §9 requires', function () {
        $this->actingAs($this->applicant)
            ->get(route('checkout.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('onboarding/checkout')
                ->has('quote.lines', 2)
                ->where('quote.lines.0.type', 'registration_fee')
                ->where('quote.lines.1.type', 'package_fee')
                ->where('quote.total.minor_units', 600000),
            );
    });

    it('sends the user to choose a package when none is pending', function () {
        UserPackage::query()->update(['status' => UserPackageStatus::Superseded]);

        $this->actingAs($this->applicant)
            ->get(route('checkout.show'))
            ->assertRedirect(route('packages.index'));
    });

    it('records a payment and redirects to the gateway', function () {
        Http::fake([
            '*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://pay.test/go']),
        ]);

        $this->actingAs($this->applicant)
            ->post(route('checkout.pay'), ['gateway' => 'sslcommerz'])
            ->assertRedirect('https://pay.test/go');

        $payment = Payment::first();

        expect($payment->amount_minor->minorUnits)->toBe(600000)
            ->and($payment->status)->toBe(PaymentStatus::Initiated)
            ->and($payment->gateway)->toBe('sslcommerz')
            ->and($payment->allocations)->toHaveCount(2);
    });

    it('reuses the same payment on a double submit', function () {
        // §26.4. Two clicks must not become two payments.
        Http::fake([
            '*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://pay.test/go']),
        ]);

        $this->actingAs($this->applicant)->post(route('checkout.pay'), ['gateway' => 'sslcommerz']);
        $this->actingAs($this->applicant)->post(route('checkout.pay'), ['gateway' => 'sslcommerz']);

        expect(Payment::count())->toBe(1);
    });

    it('rejects a gateway that is not available', function () {
        $this->actingAs($this->applicant)
            ->post(route('checkout.pay'), ['gateway' => 'bkash'])
            ->assertSessionHasErrors('gateway');
    });

    it('keeps the payment recoverable when the gateway will not start', function () {
        Http::fake(['*' => Http::response(['status' => 'FAILED', 'failedreason' => 'Store inactive'])]);

        $this->actingAs($this->applicant)
            ->post(route('checkout.pay'), ['gateway' => 'sslcommerz'])
            ->assertSessionHasErrors('gateway');

        // The draft survives so the user can retry without re-quoting.
        expect(Payment::first()->status)->toBe(PaymentStatus::Draft);
    });

    it('never takes the amount from the request', function () {
        // §36.1: a submitted total would be the obvious thing to edit.
        Http::fake([
            '*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://pay.test/go']),
        ]);

        $this->actingAs($this->applicant)->post(route('checkout.pay'), [
            'gateway' => 'sslcommerz',
            'amount' => 1,
            'total' => 1,
        ]);

        expect(Payment::first()->amount_minor->minorUnits)->toBe(600000);
    });
});

describe('the gateway webhook', function () {
    it('rejects an unsigned request', function () {
        // The signature is the only thing between this and an anonymous claim
        // that money arrived.
        $this->postJson(route('webhooks.payment', 'sslcommerz'), [
            'tran_id' => 'PAY-1',
            'status' => 'VALID',
        ])->assertStatus(401);
    });

    it('rejects an unknown gateway', function () {
        $this->postJson(route('webhooks.payment', 'not-a-gateway'), [])
            ->assertStatus(404);
    });

    it('needs no CSRF token', function () {
        // A gateway cannot carry one; it is refused on the signature instead.
        $this->post(route('webhooks.payment', 'sslcommerz'), [])
            ->assertStatus(401);
    });
});
