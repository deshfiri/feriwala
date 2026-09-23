<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Wallet\Actions\CaptureDepositObligation;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Actions\PlanWalletTopUp;
use App\Domain\Wallet\Models\DepositRule;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Support\Money\Money;
use App\Support\Rules\RuleScope;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Putting money into a wallet (P2-19, §24, §26.3).
 *
 * The browser sends one number. Everything else — what that money would do,
 * which purpose it belongs to, what it may not be less than — is decided on the
 * server, and settlement stays where it already was.
 */

beforeEach(function () {
    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

    $this->account = testBusinessAccount(AccountStatus::Active);
    $this->wallet = app(OpenWallet::class)->handle($this->account);
});

/**
 * @param  array<string, mixed>  $rule
 */
function topUpRule(array $rule = []): void
{
    DepositRule::create([
        'scope' => RuleScope::Global,
        'required_initial_deposit' => Money::fromDecimal('3000.00'),
        'minimum_balance' => Money::zero(),
        'currency_code' => 'BDT',
        'effective_from' => CarbonImmutable::now()->subMonth(),
        'is_active' => true,
        ...$rule,
    ]);

    app(CaptureDepositObligation::class)->handle(test()->account);
}

describe('planning', function () {
    it('splits what the money would do', function () {
        /*
         * "Top up ৳5,000" and "top up ৳5,000, of which ৳3,000 has to stay" are
         * different sentences, and the person paying deserves the second.
         */
        topUpRule();

        $plan = app(PlanWalletTopUp::class)->handle($this->wallet->refresh(), Money::fromDecimal('5000.00'));

        expect($plan->toObligation->toDecimal())->toBe('3000.00')
            ->and($plan->toUsable->toDecimal())->toBe('2000.00')
            ->and($plan->purpose)->toBe(PaymentPurpose::WalletDeposit);
    });

    it('calls it a top-up when no deposit is owed', function () {
        // §26.3 keeps the two apart, and so does the ledger.
        $plan = app(PlanWalletTopUp::class)->handle($this->wallet, Money::fromDecimal('5000.00'));

        expect($plan->purpose)->toBe(PaymentPurpose::WalletTopUp)
            ->and($plan->toObligation->toDecimal())->toBe('0.00')
            ->and($plan->toUsable->toDecimal())->toBe('5000.00');
    });

    it('refuses an amount below the configured minimum', function () {
        topUpRule(['required_top_up' => Money::fromDecimal('1000.00')]);

        expect(fn () => app(PlanWalletTopUp::class)->handle($this->wallet->refresh(), Money::fromDecimal('50.00')))
            ->toThrow(ValidationException::class);
    });

    it('refuses nothing at all', function () {
        expect(fn () => app(PlanWalletTopUp::class)->handle($this->wallet, Money::zero()))
            ->toThrow(ValidationException::class);
    });

    it('falls back to a floor when no minimum is configured', function () {
        // A one-taka top-up costs more to process than it adds.
        expect(app(PlanWalletTopUp::class)->minimumFor($this->wallet)->toDecimal())
            ->toBe(PlanWalletTopUp::FLOOR);
    });
});

describe('the screen', function () {
    it('shows what is required and what would clear it', function () {
        topUpRule();

        $this->actingAs($this->account->owner)
            ->get(route('wallet.top-up.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('wallet/top-up')
                ->where('suggested.amount', '3000.00')
                ->where('balances.obligation_shortfall.amount', '3000.00')
                ->has('minimum')
                ->has('gateways'));
    });

    it('is closed to somebody with no business account', function () {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actingAs(testPlatformStaff(PlatformRole::WalletManager))
            ->get(route('wallet.top-up.create'))
            ->assertRedirect();
    });
});

describe('starting one', function () {
    it('records a payment and sends the person to the gateway', function () {
        Http::fake(['*' => Http::response([
            'status' => 'SUCCESS',
            'GatewayPageURL' => 'https://sandbox.example/redirect',
        ])]);

        topUpRule();

        $this->actingAs($this->account->owner)
            ->post(route('wallet.top-up.store'), [
                'amount' => '5000.00',
                'gateway' => 'sslcommerz',
            ])
            ->assertRedirect('https://sandbox.example/redirect');

        $payment = Payment::query()->firstOrFail();

        expect($payment->purpose)->toBe(PaymentPurpose::WalletDeposit)
            ->and($payment->amount->toDecimal())->toBe('5000.00')
            // Feriwala keeps nothing: the money becomes the account's balance.
            ->and($payment->revenue->toDecimal())->toBe('0.00')
            ->and($payment->gateway)->toBe('sslcommerz');
    });

    it('refuses a total the browser made up', function () {
        // §36.1. The amount is checked against what the account is required to
        // hold, not against whatever the page was rendered with.
        topUpRule(['required_top_up' => Money::fromDecimal('1000.00')]);

        $this->actingAs($this->account->owner)
            ->post(route('wallet.top-up.store'), [
                'amount' => '0.01',
                'gateway' => 'sslcommerz',
            ])
            ->assertSessionHasErrors('amount');

        expect(Payment::query()->count())->toBe(0);
    });

    it('refuses a gateway that is not enabled', function () {
        $this->actingAs($this->account->owner)
            ->post(route('wallet.top-up.store'), [
                'amount' => '5000.00',
                'gateway' => 'not-a-gateway',
            ])
            ->assertSessionHasErrors('gateway');
    });

    it('reuses the open attempt when somebody clicks twice', function () {
        Http::fake(['*' => Http::response([
            'status' => 'SUCCESS',
            'GatewayPageURL' => 'https://sandbox.example/redirect',
        ])]);

        $payload = ['amount' => '5000.00', 'gateway' => 'sslcommerz'];

        $this->actingAs($this->account->owner)->post(route('wallet.top-up.store'), $payload);
        $this->actingAs($this->account->owner)->post(route('wallet.top-up.store'), $payload);

        expect(Payment::query()->count())->toBe(1);
    });
});

describe('when the gateway posts the payer back', function () {
    it('credits the wallet once the gateway confirms, with no session or form token, and writes no cookie', function () {
        topUpRule();

        $answers = new ArrayObject(['status' => 'VALID', 'currency_amount' => '5000.00', 'currency_type' => 'BDT']);

        Http::fake(fn ($request) => str_contains($request->url(), 'gwprocess')
            ? Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://sandbox.example/redirect'])
            : Http::response($answers->getArrayCopy()));

        $this->actingAs($this->account->owner)
            ->post(route('wallet.top-up.store'), ['amount' => '5000.00', 'gateway' => 'sslcommerz'])
            ->assertRedirect('https://sandbox.example/redirect');

        $payment = Payment::query()->firstOrFail();
        $answers['tran_id'] = $payment->reference;

        $fields = ['tran_id' => $payment->reference, 'val_id' => 'VAL-TOPUP', 'status' => 'VALID'];
        $signed = [...$fields, 'store_passwd' => md5('pass')];
        ksort($signed);
        $pairs = array_map(fn (string $key, string $value) => $key.'='.$value, array_keys($signed), $signed);

        $this->app['auth']->forgetGuards();

        $response = $this->post(route('checkout.return'), $fields + [
            'verify_key' => 'tran_id,val_id,status',
            'verify_sign' => md5(implode('&', $pairs)),
        ]);

        $response->assertStatus(303)->assertRedirect(route('checkout.return'));

        expect($response->headers->getCookies())->toBe([])
            ->and($payment->refresh()->status->value)->toBe('paid')
            ->and($this->wallet->refresh()->total->toDecimal())->toBe('5000.00')
            ->and(LedgerEntry::query()->count())->toBe(1);
    });
});

describe('when it settles', function () {
    it('credits the wallet exactly once', function () {
        topUpRule();

        $payment = Payment::create([
            'business_account_id' => $this->account->id,
            'purpose' => PaymentPurpose::WalletDeposit,
            'status' => PaymentStatus::Initiated,
            'gateway' => 'sslcommerz',
            'amount' => Money::fromDecimal('5000.00'),
            'currency_code' => 'BDT',
        ]);

        Http::fake(['*' => Http::response([
            'status' => 'VALID',
            'tran_id' => $payment->reference,
            'currency_amount' => '5000.00',
            'currency_type' => 'BDT',
        ])]);

        app(SettlePayment::class)->handle($payment, 'VAL-1');
        app(SettlePayment::class)->handle($payment->fresh(), 'VAL-1');

        expect($this->wallet->refresh()->total->toDecimal())->toBe('5000.00')
            ->and(LedgerEntry::query()->count())->toBe(1)
            ->and($payment->fresh()->wallet_credited_at)->not->toBeNull()
            ->and($payment->fresh()->wallet_credit_failed_at)->toBeNull();
    });

    it('flags the payment when the credit cannot be made', function () {
        /*
         * Confirmed money that reached no wallet is the one failure here that
         * somebody has to act on — and a line in a log file is not something an
         * administrator will find.
         */
        $other = testBusinessAccount(AccountStatus::Active);

        $payment = Payment::create([
            'business_account_id' => $other->id,
            'purpose' => PaymentPurpose::WalletTopUp,
            'status' => PaymentStatus::Initiated,
            'gateway' => 'sslcommerz',
            'amount' => Money::fromDecimal('5000.00'),
            'currency_code' => 'BDT',
        ]);

        Http::fake(['*' => Http::response([
            'status' => 'VALID',
            'tran_id' => $payment->reference,
            'currency_amount' => '5000.00',
            'currency_type' => 'BDT',
        ])]);

        app(SettlePayment::class)->handle($payment, 'VAL-2');

        $payment->refresh();

        expect($payment->status)->toBe(PaymentStatus::Paid)
            ->and($payment->wallet_credit_failed_at)->not->toBeNull()
            ->and($payment->wallet_credit_failure_reason)->toContain('no wallet');
    });

    it('lets an administrator credit it afterwards, once', function () {
        $this->seed(RolesAndPermissionsSeeder::class);

        $payment = Payment::create([
            'business_account_id' => $this->account->id,
            'purpose' => PaymentPurpose::WalletTopUp,
            'status' => PaymentStatus::Paid,
            'gateway' => 'sslcommerz',
            'amount' => Money::fromDecimal('2500.00'),
            'currency_code' => 'BDT',
            'wallet_credit_failed_at' => now(),
            'wallet_credit_failure_reason' => 'Something went wrong.',
        ]);

        $manager = testPlatformStaff(PlatformRole::WalletManager);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->actingAs($manager)
                ->withSession(['auth.password_confirmed_at' => time()])
                ->post(route('admin.payments.wallet-credit', $payment->public_id))
                ->assertRedirect();
        }

        expect($this->wallet->refresh()->total->toDecimal())->toBe('2500.00')
            ->and(LedgerEntry::query()->count())->toBe(1)
            ->and($payment->fresh()->wallet_credit_failed_at)->toBeNull();
    });

    it('refuses the retry to somebody without the permission', function () {
        $this->seed(RolesAndPermissionsSeeder::class);

        $payment = Payment::create([
            'business_account_id' => $this->account->id,
            'purpose' => PaymentPurpose::WalletTopUp,
            'status' => PaymentStatus::Paid,
            'amount' => Money::fromDecimal('2500.00'),
            'currency_code' => 'BDT',
        ]);

        $this->actingAs(testPlatformStaff(PlatformRole::PaymentManager))
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.payments.wallet-credit', $payment->public_id))
            ->assertForbidden();

        expect(Wallet::query()->firstOrFail()->total->toDecimal())->toBe('0.00');
    });
});
