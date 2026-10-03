<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\Exceptions\SensitiveActionRefused;
use App\Domain\Billing\Actions\SettlePaymentManually;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Exceptions\ManualSettlementRefused;
use App\Domain\Billing\Models\Payment;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

    $this->admin = testPlatformStaff(PlatformRole::Admin);
    $this->payment = manualSettlementPayment(PaymentStatus::Failed);
});

function manualSettlementPayment(PaymentStatus $status): Payment
{
    return Payment::create([
        'business_account_id' => testBusinessAccount()->id,
        'purpose' => PaymentPurpose::Activation,
        'status' => $status,
        'gateway' => 'sslcommerz',
        'amount' => Money::fromDecimal('6000.00', Currency::BDT),
        'currency_code' => 'BDT',
        'failed_at' => $status === PaymentStatus::Failed ? now() : null,
        'failure_reason' => $status === PaymentStatus::Failed ? 'The gateway did not confirm this payment.' : null,
    ]);
}

function manualSettlementGatewayAnswers(Payment $payment, string $amount = '6000.00', string $status = 'VALID'): void
{
    Http::fake(['*' => Http::response([
        'status' => $status,
        'tran_id' => $payment->reference,
        'currency_amount' => $amount,
        'currency_type' => 'BDT',
    ])]);
}

function manualSettlementPost(mixed $test, ?Payment $payment = null, array $overrides = [])
{
    $payment ??= $test->payment;

    return $test->actingAs($test->admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('admin.payments.manual-settlement', $payment->public_id), [
            'gateway_reference' => 'VAL-MANUAL-1',
            'reason' => 'Customer showed the bank receipt; money is in the merchant account.',
            'confirm' => '1',
            ...$overrides,
        ]);
}

describe('marking a failed payment paid', function () {
    it('settles it when the gateway confirms the transaction', function () {
        manualSettlementGatewayAnswers($this->payment);

        manualSettlementPost($this)->assertSessionHasNoErrors();

        $payment = $this->payment->fresh();

        expect($payment->status)->toBe(PaymentStatus::Paid)
            ->and($payment->gateway_reference)->toBe('VAL-MANUAL-1')
            ->and($payment->completed_at)->not->toBeNull()
            ->and($payment->settled_amount->toDecimal())->toBe('6000.00')
            ->and($payment->reconciliation_reason)->toContain('gateway confirmed');
    });

    it('writes an audit entry carrying the reason', function () {
        manualSettlementGatewayAnswers($this->payment);

        manualSettlementPost($this);

        $entry = DB::table('audit_logs')->where('action', 'billing.payment_settled_manually')->first();

        expect($entry)->not->toBeNull()
            ->and($entry->reason)->toContain('bank receipt');
    });

    it('refuses without the override when the gateway cannot confirm it', function () {
        manualSettlementGatewayAnswers($this->payment, status: 'FAILED');

        manualSettlementPost($this)->assertSessionHasErrors('gateway_reference');

        expect($this->payment->fresh()->status)->toBe(PaymentStatus::Failed);
    });

    it('settles on staff say-so when the override is ticked and says so on the record', function () {
        manualSettlementGatewayAnswers($this->payment, status: 'FAILED');

        manualSettlementPost($this, overrides: ['override' => '1'])->assertSessionHasNoErrors();

        $payment = $this->payment->fresh();

        expect($payment->status)->toBe(PaymentStatus::Paid)
            ->and($payment->reconciliation_reason)->toContain('staff override');
    });

    it('never overrides a gateway that confirms a different amount', function () {
        manualSettlementGatewayAnswers($this->payment, amount: '10.00');

        manualSettlementPost($this, overrides: ['override' => '1'])->assertSessionHasErrors('gateway_reference');

        expect($this->payment->fresh()->status)->toBe(PaymentStatus::Failed);
    });

    it('never settles with a transaction that already paid another payment', function () {
        $other = manualSettlementPayment(PaymentStatus::Paid);
        $other->forceFill(['gateway_reference' => 'VAL-MANUAL-1'])->save();

        manualSettlementGatewayAnswers($this->payment, status: 'FAILED');

        manualSettlementPost($this, overrides: ['override' => '1'])->assertSessionHasErrors('gateway_reference');

        expect($this->payment->fresh()->status)->toBe(PaymentStatus::Failed);
    });

    it('also settles a payment that was waiting for reconciliation', function () {
        $stuck = manualSettlementPayment(PaymentStatus::ReconciliationRequired);
        manualSettlementGatewayAnswers($stuck);

        manualSettlementPost($this, $stuck)->assertSessionHasNoErrors();

        expect($stuck->fresh()->status)->toBe(PaymentStatus::Paid);
    });

    it('changes nothing the second time', function () {
        manualSettlementGatewayAnswers($this->payment);

        manualSettlementPost($this);
        $completedAt = $this->payment->fresh()->completed_at;

        manualSettlementPost($this)->assertSessionHasNoErrors();

        expect($this->payment->fresh()->completed_at->timestamp)->toBe($completedAt->timestamp)
            ->and(DB::table('audit_logs')->where('action', 'billing.payment_settled_manually')->count())->toBe(1);
    });

    it('does not offer a draft that never reached a gateway', function () {
        $draft = manualSettlementPayment(PaymentStatus::Draft);
        manualSettlementGatewayAnswers($draft);

        manualSettlementPost($this, $draft)->assertSessionHasErrors('gateway_reference');

        expect($draft->fresh()->status)->toBe(PaymentStatus::Draft);
    });
});

describe('who may do it', function () {
    it('refuses staff without the permission', function () {
        manualSettlementGatewayAnswers($this->payment);
        $this->admin = testPlatformStaff(PlatformRole::OrderManager);

        manualSettlementPost($this)->assertSessionHasErrors('confirm');

        expect($this->payment->fresh()->status)->toBe(PaymentStatus::Failed);
    });

    it('refuses an account owner', function () {
        manualSettlementGatewayAnswers($this->payment);
        $this->admin = $this->payment->businessAccount->owner;

        manualSettlementPost($this);

        expect($this->payment->fresh()->status)->toBe(PaymentStatus::Failed);
    });

    it('refuses a reason-less request at the action itself', function () {
        manualSettlementGatewayAnswers($this->payment);

        expect(fn () => app(SettlePaymentManually::class)->handle(
            payment: $this->payment,
            actor: $this->admin,
            gatewayReference: 'VAL-MANUAL-1',
            reason: '',
            override: false,
            passwordConfirmed: true,
            twoFactorEnabled: true,
        ))->toThrow(SensitiveActionRefused::class);

        expect($this->payment->fresh()->status)->toBe(PaymentStatus::Failed);
    });

    it('needs the password confirmed at the action itself', function () {
        expect(fn () => app(SettlePaymentManually::class)->handle(
            payment: $this->payment,
            actor: $this->admin,
            gatewayReference: 'VAL-MANUAL-1',
            reason: 'Customer showed the bank receipt.',
            override: true,
            passwordConfirmed: false,
            twoFactorEnabled: true,
        ))->toThrow(SensitiveActionRefused::class);
    });
});

describe('the payment screen', function () {
    it('offers the form to an admin on a failed payment and not on a paid one', function () {
        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.payments.show', $this->payment->public_id))
            ->assertInertia(fn ($page) => $page
                ->where('manual.available', true)
                ->where('manual.password_confirmed', true));

        $paid = manualSettlementPayment(PaymentStatus::Paid);

        $this->actingAs($this->admin)
            ->get(route('admin.payments.show', $paid->public_id))
            ->assertInertia(fn ($page) => $page->where('manual.available', false));
    });
});

it('keeps the ordinary state machine closed to revival', function () {
    expect(fn () => $this->payment->transitionTo(PaymentStatus::Paid))->toThrow(IllegalStateTransition::class);

    expect($this->payment->canTransitionTo(PaymentStatus::Paid))->toBeFalse()
        ->and(ManualSettlementRefused::unverified('x')->overridable)->toBeTrue()
        ->and(ManualSettlementRefused::contradicted('x')->overridable)->toBeFalse();
});
