<?php

use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\GatewayOutcome;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\Gateways\SurjoPay\SurjoPayGateway;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/*
 * shurjoPay (P2-23, §26).
 *
 * Built against shurjoPay's published REST documentation. Every exchange below
 * is a recorded fixture — nothing in this file reaches shurjoPay, and nothing
 * here has been run against a real merchant account.
 */

beforeEach(function () {
    $settings = app(SettingsRepository::class);

    $settings->define('payment.surjopay.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.surjopay.sandbox.username', 'payment', SettingType::String, 'sp_test_user', isEncrypted: true);
    $settings->define('payment.surjopay.sandbox.password', 'payment', SettingType::String, 'sp-test-password', isEncrypted: true);
    $settings->define('payment.surjopay.sandbox.prefix', 'payment', SettingType::String, 'FRW', isEncrypted: true);

    $this->gateway = app(SurjoPayGateway::class);
});

function surjoPayIntent(): PaymentIntent
{
    return new PaymentIntent(
        reference: 'PAY-260901-K7M3QX9P',
        amount: Money::fromDecimal('6000.00'),
        customerName: 'Nusrat Jahan',
        customerEmail: 'nusrat@example.test',
        customerMobile: '+8801712345678',
        successUrl: 'https://erp.feriwala.test/payment/success',
        failUrl: 'https://erp.feriwala.test/payment/fail',
        cancelUrl: 'https://erp.feriwala.test/payment/cancel',
        ipnUrl: 'https://erp.feriwala.test/webhook/surjopay',
        description: 'Account activation',
    );
}

/**
 * The token call every other call depends on, plus one more response.
 */
function surjoPayFake(array $second): void
{
    Http::fakeSequence()
        ->push(['token' => 'a-token', 'store_id' => 77, 'token_type' => 'Bearer', 'sp_code' => '200'])
        ->push($second);
}

/**
 * A verification record, which shurjoPay returns inside an array.
 */
function surjoPayVerification(array $overrides = []): array
{
    return [[
        'order_id' => 'sp6405c8f848b27',
        'customer_order_id' => 'PAY-260901-K7M3QX9P',
        'currency' => 'BDT',
        'amount' => '6000.0000',
        'bank_trx_id' => 'BNK-5566',
        'bank_status' => 'Success',
        'sp_code' => '1000',
        'sp_message' => 'Success',
        'transaction_status' => 'Completed',
        ...$overrides,
    ]];
}

describe('initiating', function () {
    it('authenticates first and sends the amount as a decimal string', function () {
        surjoPayFake([
            'checkout_url' => 'https://sandbox.securepay.shurjopayment.com/spaycheckout/?token=abc',
            'sp_order_id' => 'sp6405ae1473f63',
            'customer_order_id' => 'PAY-260901-K7M3QX9P',
            'transactionStatus' => 'Initiated',
        ]);

        $redirect = $this->gateway->initiate(surjoPayIntent());

        expect($redirect->url)->toBe('https://sandbox.securepay.shurjopayment.com/spaycheckout/?token=abc')
            // shurjoPay's own order id, which is what verification is addressed
            // to. Ours travels back as customer_order_id.
            ->and($redirect->gatewayReference)->toBe('sp6405ae1473f63');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/get_token')
            && $request['username'] === 'sp_test_user');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/secret-pay')) {
                return false;
            }

            // 600000 poisha must reach the gateway as "6000.00".
            $body = $request->body();

            return str_contains($body, '6000.00')
                && str_contains($body, 'PAY-260901-K7M3QX9P')
                && str_contains($body, 'FRW');
        });
    });

    it('uses the sandbox host in sandbox mode', function () {
        surjoPayFake(['checkout_url' => 'https://pay.test/go', 'sp_order_id' => 'sp1']);

        $this->gateway->initiate(surjoPayIntent());

        Http::assertSent(fn ($request) => str_starts_with($request->url(), SurjoPayGateway::SANDBOX_HOST));
    });

    it('sends a stated placeholder rather than inventing an address', function () {
        // A plausible address is one that reads as the payer's own on a receipt
        // they never gave one for.
        surjoPayFake(['checkout_url' => 'https://pay.test/go', 'sp_order_id' => 'sp1']);

        $this->gateway->initiate(surjoPayIntent());

        Http::assertSent(fn ($request) => ! str_contains($request->url(), '/api/secret-pay')
            || str_contains($request->body(), SurjoPayGateway::ADDRESS_NOT_COLLECTED));
    });

    it('throws when authentication is refused', function () {
        Http::fake(['*' => Http::response(['message' => 'Invalid credentials', 'sp_code' => '1011'])]);

        expect(fn () => $this->gateway->initiate(surjoPayIntent()))
            ->toThrow(GatewayUnavailable::class, 'authentication was refused');
    });

    it('throws when no checkout url comes back', function () {
        surjoPayFake(['message' => 'Store is inactive']);

        expect(fn () => $this->gateway->initiate(surjoPayIntent()))
            ->toThrow(GatewayUnavailable::class, 'Store is inactive');
    });

    it('refuses to run without configured credentials', function () {
        app(SettingsRepository::class)->set('payment.surjopay.sandbox.prefix', '');

        Http::fake();

        expect(fn () => $this->gateway->initiate(surjoPayIntent()))
            ->toThrow(GatewayUnavailable::class, 'No credentials are configured');
    });
});

describe('the browser return is never trusted', function () {
    it('reports a return as pending, whatever it claims', function () {
        $result = $this->gateway->handleCallback(
            Request::create('/payment/success', 'GET', [
                'order_id' => 'sp6405c8f848b27',
                'customer_order_id' => 'PAY-260901-K7M3QX9P',
            ]),
        );

        expect($result->outcome)->toBe(GatewayOutcome::Pending)
            ->and($result->outcome->releasesValue())->toBeFalse()
            ->and($result->gatewayReference)->toBe('sp6405c8f848b27');
    });

    it('reports a return naming no transaction as failed', function () {
        expect($this->gateway->handleCallback(Request::create('/payment/success'))->outcome)
            ->toBe(GatewayOutcome::Failed);
    });
});

describe('verification is the authoritative answer', function () {
    it('treats only sp_code 1000 as paid', function () {
        surjoPayFake(surjoPayVerification());

        $result = $this->gateway->verify('sp6405c8f848b27');

        expect($result->isPaid())->toBeTrue()
            ->and($result->amount->toDecimal())->toBe('6000.00')
            ->and($result->reference)->toBe('PAY-260901-K7M3QX9P')
            ->and($result->settlementReference)->toBe('BNK-5566');
    });

    it('reads a documented decline as failed', function () {
        surjoPayFake(surjoPayVerification([
            'sp_code' => '1001',
            'sp_message' => 'Declined by issuing bank',
        ]));

        $result = $this->gateway->verify('sp6405c8f848b27');

        expect($result->outcome)->toBe(GatewayOutcome::Failed)
            ->and($result->errorCode)->toBe('1001');
    });

    it('reads a customer cancellation as cancelled, not failed', function () {
        surjoPayFake(surjoPayVerification(['sp_code' => '1002', 'sp_message' => 'Cancel']));

        expect($this->gateway->verify('sp6405c8f848b27')->outcome)->toBe(GatewayOutcome::Cancelled);
    });

    it('leaves an undocumented code pending rather than abandoning the money', function () {
        /*
         * Three codes are documented. Anything else has not been confirmed, and
         * unconfirmed is not the same as failed — marking it failed would give
         * up on money that may still be on its way.
         */
        surjoPayFake(surjoPayVerification(['sp_code' => '1005', 'sp_message' => 'Processing']));

        expect($this->gateway->verify('sp6405c8f848b27')->outcome)->toBe(GatewayOutcome::Pending);
    });

    it('reports a transaction the gateway has no record of', function () {
        surjoPayFake([]);

        $result = $this->gateway->verify('sp-nothing');

        expect($result->outcome)->toBe(GatewayOutcome::Failed)
            ->and($result->errorCode)->toBe('not_found');
    });

    it('throws rather than guessing when a success carries no amount', function () {
        surjoPayFake([['order_id' => 'sp1', 'sp_code' => '1000']]);

        expect(fn () => $this->gateway->verify('sp1'))
            ->toThrow(GatewayUnavailable::class, 'could not be understood');
    });

    it('drops a bank reference of "0", which means no bank transaction', function () {
        surjoPayFake(surjoPayVerification(['bank_trx_id' => '0']));

        expect($this->gateway->verify('sp6405c8f848b27')->settlementReference)->toBeNull();
    });
});

describe('what this driver will not do', function () {
    it('fails an unsigned notification closed, because shurjoPay does not sign one', function () {
        /*
         * Documented behaviour rather than a gap: the IPN carries an order id
         * and shurjoPay's own guidance is to verify through the API on
         * receiving one. So the capability is absent and nothing is trusted for
         * having arrived.
         */
        expect($this->gateway->verifyWebhookSignature(
            Request::create('/webhook/surjopay', 'POST', ['order_id' => 'sp1']),
        ))->toBeFalse()
            ->and($this->gateway->supports(GatewayCapability::WebhookSignature))->toBeFalse();
    });

    it('offers no refund, because shurjoPay documents no refund API', function () {
        expect($this->gateway->supports(GatewayCapability::RefundFull))->toBeFalse()
            ->and($this->gateway->supports(GatewayCapability::RefundPartial))->toBeFalse()
            ->and($this->gateway->supports(GatewayCapability::StatusQuery))->toBeFalse();
    });
});
