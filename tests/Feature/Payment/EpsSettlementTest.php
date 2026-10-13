<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Address\Actions\SaveSharedAddress;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Enums\ClientAddressType;
use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Package\Models\Package;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

/*
 * EPS end to end through checkout: the attempt id and address are saved before
 * EPS is called, a browser return settles nothing by itself, and only a
 * server-side status check that names this payment's own transaction, amount
 * and currency pays it — once.
 */

beforeEach(function () {
    $settings = app(SettingsRepository::class);
    $settings->define('billing.registration_fee', 'billing', SettingType::Money, '1000.00');
    $settings->define('billing.gateway_charge_percent', 'billing', SettingType::Decimal, '0');
    $settings->define('payment.eps.mode', 'payment', SettingType::String, 'live');
    $settings->define('payment.eps.enabled', 'payment', SettingType::Boolean, true);

    foreach (['base_url' => 'https://pgapi.eps.com.bd', 'merchant_id' => 'M', 'store_id' => 'S', 'username' => 'u', 'password' => 'p', 'hash_key' => 'k'] as $key => $value) {
        $settings->define("payment.eps.live.{$key}", 'payment', SettingType::String, $value, isEncrypted: true);
    }

    $this->account = testBusinessAccount(AccountStatus::PackageSelectionPending);
    $this->applicant = $this->account->owner;
    $this->applicant->forceFill(['mobile' => '+8801712345678'])->save();
    $this->package = Package::create(['name' => 'Growth', 'slug' => 'growth', 'fee' => Money::fromDecimal('5000.00', Currency::BDT), 'validity_days' => 365]);

    // What EPS answers to a status check, per merchantTransactionId.
    $this->statuses = [];

    Http::fake(function (ClientRequest $request) {
        $url = $request->url();

        if (str_contains($url, '/Auth/GetToken')) {
            return Http::response(['token' => 'a.b.c']);
        }

        if (str_contains($url, '/InitializeEPS')) {
            return Http::response(['TransactionId' => 'sess-1', 'RedirectURL' => 'https://pg.eps.com.bd/PG?data=sess-1']);
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $id = (string) ($query['merchantTransactionId'] ?? '');
        $answer = $this->statuses[$id] ?? ['Status' => 'Pending', 'TotalAmount' => '6000.00'];

        return Http::response(['MerchantTransactionId' => $id, 'EpsTransactionId' => 'C1', ...$answer]);
    });

    $this->giveAddress = function (): void {
        $chain = addressTestLocationChain();

        app(SaveSharedAddress::class)->handle(
            ownerType: AddressOwnerType::BusinessAccount,
            ownerId: $this->account->id,
            type: ClientAddressType::Business->value,
            contactName: 'Karim',
            contactMobile: '+8801711111111',
            divisionId: $chain['division']->id,
            districtId: $chain['district']->id,
            upazilaId: $chain['upazila']->id,
            unionId: null,
            detailedAddress: 'House 1, Road 2',
            landmark: null,
            postcode: '1207',
        );
    };
});

function epsSettlementStatusChecks(): int
{
    return Http::recorded(fn (ClientRequest $request) => str_contains($request->url(), 'CheckMerchantTransactionStatus'))->count();
}

it('saves the attempt id, the frozen address and the session before sending the payer to EPS', function () {
    ($this->giveAddress)();

    $this->actingAs($this->applicant)->post(route('packages.select', $this->package));
    $response = $this->actingAs($this->applicant)->post(route('checkout.pay'), ['gateway' => 'eps']);

    $payment = Payment::query()->where('business_account_id', $this->account->id)->latest('id')->firstOrFail();

    $response->assertRedirect('https://pg.eps.com.bd/PG?data=sess-1');

    expect($payment->gateway_reference)->toMatch('/^\d{10,}$/')
        ->and($payment->gateway_session_reference)->toBe('sess-1')
        ->and($payment->payer_address_snapshot)->toBe([
            'line' => 'House 1, Road 2',
            'city' => 'Test District',
            'state' => 'Test Division',
            'postcode' => '1207',
            'country' => 'BD',
        ]);
});

it('stops before contacting EPS when the account has no usable address', function () {
    $this->actingAs($this->applicant)->post(route('packages.select', $this->package));

    $this->actingAs($this->applicant)
        ->post(route('checkout.pay'), ['gateway' => 'eps'])
        ->assertSessionHasErrors('gateway');

    expect(session('errors')->first('gateway'))->toContain('postal address');

    Http::assertNothingSent();
});

it('gives every new attempt its own transaction id', function () {
    ($this->giveAddress)();
    $this->actingAs($this->applicant)->post(route('packages.select', $this->package));

    $this->actingAs($this->applicant)->post(route('checkout.pay'), ['gateway' => 'eps']);
    $first = Payment::query()->latest('id')->firstOrFail()->gateway_reference;

    $this->actingAs($this->applicant)->post(route('checkout.pay'), ['gateway' => 'eps']);
    $second = Payment::query()->latest('id')->firstOrFail()->refresh()->gateway_reference;

    expect($second)->not->toBe($first)->and($second)->toMatch('/^\d{10,}$/');
});

it('does not settle on a browser return alone', function () {
    ($this->giveAddress)();
    $this->actingAs($this->applicant)->post(route('packages.select', $this->package));
    $this->actingAs($this->applicant)->post(route('checkout.pay'), ['gateway' => 'eps']);
    $payment = Payment::query()->where('business_account_id', $this->account->id)->latest('id')->firstOrFail();

    // EPS has not confirmed anything (the fake answers Pending).
    $this->actingAs($this->applicant)->get(route('checkout.return', ['MerchantTransactionId' => $payment->gateway_reference, 'Status' => 'Success']));

    expect($payment->refresh()->status->isSettled())->toBeFalse()
        ->and(epsSettlementStatusChecks())->toBeGreaterThan(0);
});

it('refuses a forged return that names somebody else\'s transaction', function () {
    ($this->giveAddress)();
    $this->actingAs($this->applicant)->post(route('packages.select', $this->package));
    $this->actingAs($this->applicant)->post(route('checkout.pay'), ['gateway' => 'eps']);
    $payment = Payment::query()->where('business_account_id', $this->account->id)->latest('id')->firstOrFail();

    // A genuinely paid transaction — but not this payment's.
    $this->statuses['9999999999999999'] = ['Status' => 'Success', 'TotalAmount' => '6000.00'];

    $this->actingAs($this->applicant)->get(route('checkout.return', ['MerchantTransactionId' => '9999999999999999', 'Status' => 'Success']));

    expect($payment->refresh()->status->isSettled())->toBeFalse();
});

it('settles once, on a verified success, however many times it is replayed', function () {
    ($this->giveAddress)();
    $this->actingAs($this->applicant)->post(route('packages.select', $this->package));
    $this->actingAs($this->applicant)->post(route('checkout.pay'), ['gateway' => 'eps']);
    $payment = Payment::query()->where('business_account_id', $this->account->id)->latest('id')->firstOrFail();

    $this->statuses[$payment->gateway_reference] = ['Status' => 'Success', 'TotalAmount' => '6000.00'];

    $this->actingAs($this->applicant)->get(route('checkout.return', ['MerchantTransactionId' => $payment->gateway_reference, 'Status' => 'Success']));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->gateway_settlement_reference)->toBe('C1');

    $checksAfterFirst = epsSettlementStatusChecks();
    $completedAt = $payment->completed_at;

    $this->actingAs($this->applicant)->get(route('checkout.return', ['MerchantTransactionId' => $payment->gateway_reference, 'Status' => 'Success']));
    app(SettlePayment::class)->handle($payment, $payment->gateway_reference);
    app(SettlePayment::class)->handle($payment, $payment->gateway_reference);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->completed_at->equalTo($completedAt))->toBeTrue()
        ->and(epsSettlementStatusChecks())->toBe($checksAfterFirst);
});

it('refuses a verified success for a different amount', function () {
    ($this->giveAddress)();
    $this->actingAs($this->applicant)->post(route('packages.select', $this->package));
    $this->actingAs($this->applicant)->post(route('checkout.pay'), ['gateway' => 'eps']);
    $payment = Payment::query()->where('business_account_id', $this->account->id)->latest('id')->firstOrFail();

    $this->statuses[$payment->gateway_reference] = ['Status' => 'Success', 'TotalAmount' => '1.00'];

    $result = app(SettlePayment::class)->handle($payment, $payment->gateway_reference);

    expect($result->isPaid())->toBeFalse()
        ->and($payment->refresh()->status->isSettled())->toBeFalse();
});
