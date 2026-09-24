<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Package\Models\Package;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Integrations\Payment\GatewayNavigation;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Finder\Finder;

/**
 * Sending the payer to the gateway (§26.4, D12).
 *
 * Every page that starts a payment is an Inertia page and submits by XHR. A
 * redirect to the gateway would be followed by that XHR and refused by the
 * gateway as a cross-origin request — the payment would never open. The answer
 * to an Inertia request is `409` with `X-Inertia-Location`, which the client
 * turns into a full page visit; an ordinary form post still gets its redirect.
 */
beforeEach(function () {
    $settings = app(SettingsRepository::class);
    $settings->define('billing.registration_fee', 'billing', SettingType::Money, '1000.00');
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

    // One closure reading what the test says the gateway answers now: a second
    // Http::fake() would be merged and silently ignored.
    $this->gatewayPage = new ArrayObject(['url' => 'https://sandbox.sslcommerz.com/EasyCheckOut/abc']);

    Http::fake(fn () => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => $this->gatewayPage['url']]));

    $this->account = testBusinessAccount(AccountStatus::PackageSelectionPending);
    $this->applicant = $this->account->owner;

    $this->package = Package::create([
        'name' => 'Growth',
        'slug' => 'growth-'.Str::lower(Str::random(6)),
        'fee' => Money::fromDecimal('5000.00', Currency::BDT),
        'validity_days' => 365,
    ]);

    $this->actingAs($this->applicant)->post(route('packages.select', $this->package));
});

/**
 * The checkout submitted the way the page submits it: an Inertia XHR.
 */
function gatewayNavigationCheckout(array $headers = []): TestResponse
{
    return test()->actingAs(test()->applicant)
        ->withHeaders($headers)
        ->post(route('checkout.pay'), ['gateway' => 'sslcommerz']);
}

$inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => '1', 'X-Requested-With' => 'XMLHttpRequest'];

describe('opening the gateway', function () use ($inertia) {
    it('tells an Inertia checkout to leave the application rather than following a redirect', function () use ($inertia) {
        $response = gatewayNavigationCheckout($inertia);

        $response->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://sandbox.sslcommerz.com/EasyCheckOut/abc');

        // Nothing for an XHR to follow: the client navigates the whole page.
        expect($response->headers->get('Location'))->toBeNull()
            ->and($response->getContent())->toBe('');
    });

    it('still redirects an ordinary form post, which the browser follows itself', function () {
        gatewayNavigationCheckout()
            ->assertStatus(302)
            ->assertRedirect('https://sandbox.sslcommerz.com/EasyCheckOut/abc');
    });

    it('opens the wallet top-up the same way', function () use ($inertia) {
        $account = testBusinessAccount(AccountStatus::Active);
        app(OpenWallet::class)->handle($account);

        $this->actingAs($account->owner)
            ->withHeaders($inertia)
            ->post(route('wallet.top-up.store'), ['amount' => '5000.00', 'gateway' => 'sslcommerz'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://sandbox.sslcommerz.com/EasyCheckOut/abc');
    });

    it('does not take a second payment when the checkout is submitted twice', function () use ($inertia) {
        gatewayNavigationCheckout($inertia)->assertStatus(409);
        gatewayNavigationCheckout($inertia)->assertStatus(409);

        expect(Payment::query()->count())->toBe(1)
            ->and(Invoice::query()->count())->toBe(1);
    });

    it('refuses to send anybody to an address that cannot be opened', function () use ($inertia) {
        $this->gatewayPage['url'] = 'javascript:alert(1)';

        gatewayNavigationCheckout($inertia)->assertSessionHasErrors('gateway');

        expect(Payment::query()->where('status', 'initiated')->count())->toBe(0);
    });
});

describe('what counts as an address a payer may be sent to', function () {
    it('accepts https and refuses anything else', function () {
        expect(GatewayNavigation::isOpenable('https://sandbox.sslcommerz.com/EasyCheckOut/abc'))->toBeTrue()
            ->and(GatewayNavigation::isOpenable('http://sandbox.sslcommerz.com/pay'))->toBeFalse()
            ->and(GatewayNavigation::isOpenable('javascript:alert(1)'))->toBeFalse()
            ->and(GatewayNavigation::isOpenable('data:text/html,<script>'))->toBeFalse()
            ->and(GatewayNavigation::isOpenable('/checkout'))->toBeFalse()
            ->and(GatewayNavigation::isOpenable(''))->toBeFalse()
            // Credentials in an address are how a payer is shown one host while
            // being sent to another.
            ->and(GatewayNavigation::isOpenable('https://sslcommerz.com@evil.test/pay'))->toBeFalse();
    });

    it('allows a local stub over plain http only while developing or testing', function () {
        expect(GatewayNavigation::isOpenable('http://127.0.0.1:8001/fake-gateway'))->toBeTrue();

        app()['env'] = 'production';

        expect(GatewayNavigation::isOpenable('http://127.0.0.1:8001/fake-gateway'))->toBeFalse();

        app()['env'] = 'testing';
    });
});

describe('one way out, for every payment purpose', function () {
    it('has no controller sending a payer to a gateway by a redirect an XHR would follow', function () {
        $offenders = [];

        foreach (Finder::create()->files()->in(app_path('Http/Controllers'))->name('*.php') as $file) {
            $contents = (string) file_get_contents($file->getRealPath());

            // The storefront's own return sends a customer back to their shop,
            // which is never an Inertia request.
            if (str_contains($contents, 'redirect()->away(') && ! str_contains($file->getFilename(), 'GatewayReturnController')) {
                $offenders[] = $file->getFilename();
            }
        }

        expect($offenders)->toBe([]);
    });
});
