<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Package\Models\Package;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/*
 * Coming back from a gateway without trusting the browser (§26.4, §36).
 *
 * SSLCommerz and aamarPay return the payer with a form POST from their own site,
 * which carries neither the `SameSite=Lax` session cookie nor a CSRF token. Those
 * exact POST addresses are received outside the web middleware — no session, no
 * cookie written, no CSRF check — and send the browser on to the signed-in page
 * with a 303. No return, posted or not, is evidence of anything: the gateway is
 * asked only on a valid provider signature or an identifier we already hold, and
 * its answer alone settles, fails or leaves a payment as it was.
 */

const RETURN_SAFETY_RECEIVERS = [
    'checkout.return.receive',
    'checkout.cancelled.receive',
    'checkout.failed.receive',
    'wholesale.orders.payment.return.receive',
    'wholesale.orders.payment.cancelled.receive',
    'wholesale.orders.payment.failed.receive',
];

const RETURN_SAFETY_PAGES = [
    'checkout.return',
    'checkout.cancelled',
    'checkout.failed',
    'wholesale.orders.payment.return',
    'wholesale.orders.payment.cancelled',
    'wholesale.orders.payment.failed',
];

beforeEach(function () {
    $settings = app(SettingsRepository::class);
    $settings->define('billing.registration_fee', 'billing', SettingType::Money, 100000);
    $settings->define('billing.gateway_charge_percent', 'billing', SettingType::Decimal, '0');
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

    $this->account = testBusinessAccount(AccountStatus::PackageSelectionPending);
    $this->applicant = $this->account->owner;
    $this->package = Package::create(['name' => 'Growth', 'slug' => 'growth', 'fee_minor' => 500000, 'validity_days' => 365]);

    // What each gateway answers when asked, changeable through test().
    $this->validation = new ArrayObject(['status' => 'VALID', 'currency_amount' => '6000.00', 'currency_type' => 'BDT']);
    $this->search = new ArrayObject(['pay_status' => 'Successful', 'amount' => '6000.00', 'currency' => 'BDT', 'pg_txnid' => 'AMR-1']);

    Http::fake(function (ClientRequest $request) {
        return match (true) {
            str_contains($request->url(), 'gwprocess') => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://pay.test/go', 'sessionkey' => 'session-1']),
            str_contains($request->url(), 'trxcheck') => Http::response($this->search->getArrayCopy()),
            default => Http::response($this->validation->getArrayCopy()),
        };
    });

    $this->start = function (): Payment {
        $this->actingAs($this->applicant)->post(route('packages.select', $this->package));
        $this->actingAs($this->applicant)->post(route('checkout.pay'), ['gateway' => 'sslcommerz']);

        $payment = Payment::query()->where('business_account_id', $this->account->id)->latest('id')->firstOrFail();
        $this->validation['tran_id'] = $payment->reference;

        return $payment;
    };
});

/**
 * Fields signed the way SSLCommerz signs a return or a notification.
 *
 * @param  array<string, string>  $fields
 * @return array<string, string>
 */
function returnSafetySigned(array $fields): array
{
    $signed = [...$fields, 'store_passwd' => md5('pass')];
    ksort($signed);

    $pairs = [];

    foreach ($signed as $key => $value) {
        $pairs[] = $key.'='.$value;
    }

    return $fields + [
        'verify_key' => implode(',', array_keys($fields)),
        'verify_sign' => md5(implode('&', $pairs)),
    ];
}

function returnSafetyAsked(string $endpoint): int
{
    return Http::recorded(fn (ClientRequest $request) => str_contains($request->url(), $endpoint))->count();
}

/**
 * @return array<int, mixed>
 */
function returnSafetyMiddleware(string $name): array
{
    // The configured middleware groups reach the router when the HTTP kernel is
    // resolved; before the first request of a test they would still read as the
    // bare name `web`, and an exclusion would prove nothing.
    app(HttpKernel::class);

    $route = Route::getRoutes()->getByName($name);

    expect($route)->not->toBeNull();

    return app('router')->gatherRouteMiddleware($route);
}

describe('the routes', function () {
    it('receives a posted return outside the session, cookies and CSRF, on exactly the return addresses', function () {
        foreach (RETURN_SAFETY_RECEIVERS as $name) {
            $middleware = array_map('strval', returnSafetyMiddleware($name));

            expect(Route::getRoutes()->getByName($name)->methods())->toBe(['POST'])
                ->and($middleware)->not->toContain('web')
                ->and($middleware)->toContain('Illuminate\Routing\Middleware\ThrottleRequests:payment-returns')
                ->and($middleware)->not->toContain(StartSession::class)
                ->and($middleware)->not->toContain(PreventRequestForgery::class)
                ->and(collect($middleware)->contains(fn (string $entry) => str_starts_with($entry, Authenticate::class)))->toBeFalse();
        }
    });

    it('keeps the return pages as signed-in GET pages', function () {
        foreach (RETURN_SAFETY_PAGES as $name) {
            $middleware = array_map('strval', returnSafetyMiddleware($name));

            expect(Route::getRoutes()->getByName($name)->methods())->toBe(['GET', 'HEAD'])
                ->and($middleware)->toContain(StartSession::class)
                ->and($middleware)->toContain(PreventRequestForgery::class)
                ->and(collect($middleware)->contains(fn (string $entry) => str_starts_with($entry, Authenticate::class)))->toBeTrue();
        }
    });

    it('keeps every action a signed-in person takes behind the CSRF and origin check', function () {
        // PreventRequestForgery is the framework's CSRF middleware (ValidateCsrfToken
        // extends it); it also refuses a cross-site POST by its Sec-Fetch-Site header.
        foreach (['packages.select', 'checkout.pay', 'wallet.top-up.store', 'wholesale.orders.store', 'wholesale.orders.payment.store', 'wholesale.orders.cancellation.store'] as $name) {
            expect(array_map('strval', returnSafetyMiddleware($name)))->toContain(PreventRequestForgery::class);
        }
    });
});

describe('a gateway posting the payer back (SSLCommerz, signed)', function () {
    it('is accepted with no session or token, writes no cookie, and settles only once the gateway confirms', function () {
        $payment = ($this->start)();
        $this->app['auth']->forgetGuards();

        $response = $this->post(route('checkout.return'), returnSafetySigned(['tran_id' => $payment->reference, 'val_id' => 'val-1', 'status' => 'VALID']));

        $response->assertStatus(303)->assertRedirect(route('checkout.return'));

        expect($response->headers->getCookies())->toBe([])
            ->and(returnSafetyAsked('validationserverAPI'))->toBe(1)
            ->and($payment->refresh()->status)->toBe(PaymentStatus::Paid);

        // Back inside the session, the page reads what the gateway confirmed.
        $this->actingAs($this->applicant)
            ->get(route('checkout.return'))
            ->assertRedirect(route('onboarding.status'))
            ->assertSessionHas('success', __('payment.return.received'));
    });

    it('refuses a broken signature before asking the gateway anything', function () {
        $payment = ($this->start)();

        $forged = returnSafetySigned(['tran_id' => $payment->reference, 'val_id' => 'val-1', 'status' => 'VALID']);
        $forged['verify_sign'] = str_repeat('0', 32);

        $this->post(route('checkout.return'), $forged)->assertStatus(303)->assertRedirect(route('checkout.return'));

        expect(returnSafetyAsked('validationserverAPI'))->toBe(0)
            ->and($payment->refresh()->status)->toBe(PaymentStatus::Initiated)
            ->and(PaymentLog::query()->where('event', 'return')->where('outcome', 'refused_signature')->count())->toBe(1);
    });

    it('settles once however many times the same return is posted', function () {
        $payment = ($this->start)();
        $body = returnSafetySigned(['tran_id' => $payment->reference, 'val_id' => 'val-1', 'status' => 'VALID']);

        foreach (range(1, 3) as $attempt) {
            $this->post(route('checkout.return'), $body)->assertStatus(303);
        }

        expect(returnSafetyAsked('validationserverAPI'))->toBe(1)
            ->and($payment->refresh()->status)->toBe(PaymentStatus::Paid)
            ->and(PaymentLog::query()->where('event', 'verify')->count())->toBe(1);
    });

    it('believes the gateway over a forged success', function () {
        $payment = ($this->start)();
        $this->validation['status'] = 'INVALID_TRANSACTION';

        $this->post(route('checkout.return'), returnSafetySigned(['tran_id' => $payment->reference, 'val_id' => 'val-1', 'status' => 'VALID']));

        expect($payment->refresh()->status)->not->toBe(PaymentStatus::Paid)
            ->and($this->account->fresh()->status)->not->toBe(AccountStatus::Active);
    });

    it('closes nothing on a posted cancel or failure the gateway has not confirmed', function () {
        $payment = ($this->start)();

        $this->post(route('checkout.cancelled'), ['tran_id' => $payment->reference, 'status' => 'CANCELLED'])
            ->assertStatus(303)->assertRedirect(route('checkout.cancelled'));
        $this->post(route('checkout.failed'), ['tran_id' => $payment->reference, 'status' => 'FAILED'])
            ->assertStatus(303)->assertRedirect(route('checkout.failed'));

        expect($payment->refresh()->status)->toBe(PaymentStatus::Initiated)
            ->and(returnSafetyAsked('validationserverAPI'))->toBe(0);
    });

    it('fails the attempt when a signed failure is confirmed by the gateway', function () {
        $payment = ($this->start)();
        $this->validation['status'] = 'FAILED';

        $this->post(route('checkout.failed'), returnSafetySigned(['tran_id' => $payment->reference, 'val_id' => 'val-9', 'status' => 'FAILED']));

        expect($payment->refresh()->status)->toBe(PaymentStatus::Failed);
    });

    it('never shows one account another account\'s transaction', function () {
        $mine = ($this->start)();

        $other = testBusinessAccount(AccountStatus::PackageSelectionPending);
        $this->actingAs($other->owner)->post(route('packages.select', $this->package));
        $this->actingAs($other->owner)->post(route('checkout.pay'), ['gateway' => 'sslcommerz']);
        $theirs = Payment::query()->where('business_account_id', $other->id)->sole();
        $this->validation['tran_id'] = $theirs->reference;

        // Their genuine, signed return lands without any session: the gateway's word settles their payment…
        $this->post(route('checkout.return'), returnSafetySigned(['tran_id' => $theirs->reference, 'val_id' => 'val-2', 'status' => 'VALID']));

        expect($theirs->refresh()->status)->toBe(PaymentStatus::Paid);

        // …and the signed-in page shows each person only their own.
        $this->actingAs($this->applicant)
            ->get(route('checkout.return'))
            ->assertRedirect(route('checkout.show'))
            ->assertSessionHas('info', __('payment.return.checking'));

        expect($mine->refresh()->status)->toBe(PaymentStatus::Initiated);
    });

    it('sends a person with no session to sign in rather than showing a return page', function () {
        $this->app['auth']->forgetGuards();

        foreach (['checkout.return', 'checkout.cancelled', 'checkout.failed'] as $name) {
            $this->get(route($name))->assertRedirect(route('login'));
        }
    });

    it('answers every posted return the same way, whatever it found', function () {
        $payment = ($this->start)();

        $known = $this->post(route('checkout.return'), ['tran_id' => $payment->reference, 'status' => 'VALID']);
        $unknown = $this->post(route('checkout.return'), ['tran_id' => 'PAY-NOBODY', 'status' => 'VALID']);
        $empty = $this->post(route('checkout.return'), []);

        foreach ([$known, $unknown, $empty] as $response) {
            $response->assertStatus(303)->assertRedirect(route('checkout.return'));
        }
    });
});

describe('a provider that does not sign its returns (aamarPay)', function () {
    beforeEach(function () {
        $settings = app(SettingsRepository::class);
        $settings->define('payment.amarpay.mode', 'payment', SettingType::String, 'sandbox');
        $settings->define('payment.amarpay.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
        $settings->define('payment.amarpay.sandbox.signature_key', 'payment', SettingType::String, 'key', isEncrypted: true);

        $this->payment = Payment::create([
            'business_account_id' => $this->account->id,
            'purpose' => PaymentPurpose::Activation,
            'status' => PaymentStatus::Initiated,
            'amount_minor' => 600000,
            'currency_code' => 'BDT',
            'gateway' => 'amarpay',
        ]);

        $this->search['mer_txnid'] = $this->payment->reference;
    });

    it('asks the gateway by our own reference and settles on its answer', function () {
        $this->post(route('checkout.return'), ['mer_txnid' => $this->payment->reference, 'pay_status' => 'Successful'])
            ->assertStatus(303);

        expect(returnSafetyAsked('trxcheck'))->toBe(1)
            ->and($this->payment->refresh()->status)->toBe(PaymentStatus::Paid);
    });

    it('settles nothing the gateway has not confirmed, whatever the browser claims', function () {
        $this->search['pay_status'] = 'Pending';

        $this->post(route('checkout.return'), ['mer_txnid' => $this->payment->reference, 'pay_status' => 'Successful']);

        expect($this->payment->refresh()->status)->not->toBe(PaymentStatus::Paid);
    });

    it('asks nothing about a reference nobody holds', function () {
        $this->post(route('checkout.return'), ['mer_txnid' => 'PAY-NOBODY', 'pay_status' => 'Successful'])
            ->assertStatus(303);

        expect(returnSafetyAsked('trxcheck'))->toBe(0)
            ->and($this->payment->refresh()->status)->toBe(PaymentStatus::Initiated);
    });
});
