<?php

use App\Domain\Account\Enums\AccountType;
use App\Domain\Billing\Actions\SettlePaymentManually;
use App\Domain\Billing\Actions\UsePlatformGateway;
use App\Domain\Billing\Enums\GatewayOwnership;
use App\Domain\Billing\Enums\PartnerGatewayFallbackReason;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Exceptions\ManualSettlementRefused;
use App\Domain\Billing\Models\PartnerGatewayConfigurationVersion;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Actions\ChangeOrderPaymentMethod;
use App\Domain\Order\Actions\ConfirmOrderPayment;
use App\Domain\Order\Actions\FreezePlatformReceivable;
use App\Domain\Order\Actions\InitiateOrderPayment;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\OrderRefused;
use App\Domain\Order\Exceptions\PlatformReceivableRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderPlatformReceivable;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * Taking an order through a Partner's own gateway (D36, Model B): who qualifies,
 * what is frozen before the gateway is ever contacted, and that a payment started
 * with a Partner's gateway is never quietly moved to Feriwala's.
 *
 * Two units at ৳2,600 and ৳60 delivery: the customer pays ৳5,260. Feriwala keeps
 * its suggested ৳2,500 each and the delivery, so its receivable is ৳5,060.
 */

beforeEach(function () {
    partnerCheckoutWorld();
});

/**
 * What a placed order's payment says about whose it is.
 *
 * @return array{ownership: GatewayOwnership|null, destination: string|null, version: int|null, gateway: string|null}
 */
function partnerCheckoutOwnership(Order $order): array
{
    $payment = $order->payment()->first();

    return [
        'ownership' => $payment->gateway_ownership,
        'destination' => $payment->funds_destination,
        'version' => $payment->partner_gateway_configuration_version_id,
        'gateway' => $payment->gateway,
    ];
}

function partnerCheckoutFallbackReason(Order $order): ?string
{
    return PaymentLog::query()
        ->where('payment_id', $order->payment_id)
        ->where('event', 'partner_gateway_not_used')
        ->value('outcome');
}

describe('an eligible order', function () {
    it('takes the Partner\'s own gateway, frozen on the payment with the exact version', function () {
        $version = PartnerGatewayConfigurationVersion::query()->sole();
        $order = partnerCheckoutPlace();
        $payment = $order->payment;

        expect($payment->gateway_ownership)->toBe(GatewayOwnership::Partner)
            ->and($payment->funds_destination)->toBe('partner')
            ->and($payment->website_id)->toBe($this->website->id)
            ->and($payment->partner_gateway_configuration_version_id)->toBe($version->id)
            ->and($payment->gateway)->toBe('sslcommerz')
            ->and($payment->gateway_mode)->toBe('sandbox')
            ->and($payment->amount->toDecimal())->toBe('5260.00')
            ->and($order->status)->toBe(OrderStatus::PaymentPending)
            ->and(partnerCheckoutFallbackReason($order))->toBeNull();
    });

    it('freezes the receivable with the order, before the gateway is contacted', function () {
        $order = partnerCheckoutPlace();

        $receivable = OrderPlatformReceivable::query()->where('order_id', $order->id)->sole();

        expect($receivable->total->toDecimal())->toBe('5060.00')
            ->and($receivable->payment_id)->toBe($order->payment_id)
            ->and($receivable->lines->pluck('line_amount')->map->toDecimal()->all())->toBe(['5000.00', '60.00']);

        // Placing an order never calls a provider: that waits for the customer to pay.
        Http::assertNothingSent();
    });

    it('starts the payment with the Partner\'s own credentials, never Feriwala\'s', function () {
        $order = partnerCheckoutPlace();

        $url = app(InitiateOrderPayment::class)->handle($order);

        expect($url)->toStartWith('https://pay.test/go/')
            ->and($order->payment->refresh()->status)->toBe(PaymentStatus::Initiated)
            ->and(partnerCheckoutSentStore('partner-store'))->toBeTrue()
            ->and(partnerCheckoutSentStore('platform-store'))->toBeFalse();
    });

    it('tells the provider to notify an address that names this payment', function () {
        $order = partnerCheckoutPlace();

        app(InitiateOrderPayment::class)->handle($order);

        $sent = Http::recorded()->first(fn (array $pair) => str_contains($pair[0]->url(), '/gwprocess/v4/api.php'))[0];

        expect($sent['ipn_url'])->toBe(route('webhooks.partner-payment', ['sslcommerz', $order->payment->public_id]));
    });

    it('is still started with the original credentials after the Partner rotates them', function () {
        $order = partnerCheckoutPlace();

        partnerCheckoutConfigure('rotated-store', 'rotated-pass');

        app(InitiateOrderPayment::class)->handle($order);

        expect(partnerCheckoutSentStore('partner-store'))->toBeTrue()
            ->and(partnerCheckoutSentStore('rotated-store'))->toBeFalse();
    });

    it('is still started after the Partner switches back and Admin switches the feature off', function () {
        $order = partnerCheckoutPlace();

        app(UsePlatformGateway::class)->handle($this->owner, $this->website);
        partnerCheckoutEnable(enabled: false);

        app(InitiateOrderPayment::class)->handle($order);

        expect(partnerCheckoutSentStore('partner-store'))->toBeTrue()
            ->and($order->payment->refresh()->gateway_ownership)->toBe(GatewayOwnership::Partner);
    });

    it('is taken as the Partner\'s even when the Storefront names that provider itself', function () {
        $order = partnerCheckoutPlace(gateway: 'sslcommerz');

        expect(partnerCheckoutOwnership($order)['ownership'])->toBe(GatewayOwnership::Partner);
    });
});

describe('when the Partner\'s gateway cannot be used', function () {
    it('takes Feriwala\'s gateway, and says so, when Admin has switched the feature off', function () {
        partnerCheckoutEnable(enabled: false);

        $order = partnerCheckoutPlace();

        expect(partnerCheckoutOwnership($order))->toMatchArray([
            'ownership' => GatewayOwnership::Platform,
            'destination' => 'platform',
            'version' => null,
            'gateway' => 'sslcommerz',
        ])
            ->and(partnerCheckoutFallbackReason($order))->toBe(PartnerGatewayFallbackReason::FeatureDisabled->value)
            ->and(OrderPlatformReceivable::query()->count())->toBe(0);
    });

    it('takes Feriwala\'s gateway when Admin has taken the provider off the list', function () {
        partnerCheckoutEnable(['stripe']);

        $order = partnerCheckoutPlace();

        expect(partnerCheckoutOwnership($order)['ownership'])->toBe(GatewayOwnership::Platform)
            ->and(partnerCheckoutFallbackReason($order))->toBe(PartnerGatewayFallbackReason::ProviderNotAllowed->value);
    });

    it('takes Feriwala\'s gateway when a sandbox configuration would take a real order in production', function () {
        app()->detectEnvironment(fn () => 'production');

        $order = partnerCheckoutPlace();

        expect(partnerCheckoutOwnership($order)['ownership'])->toBe(GatewayOwnership::Platform)
            ->and(partnerCheckoutFallbackReason($order))->toBe(PartnerGatewayFallbackReason::SandboxNotAllowedInProduction->value);
    });

    it('takes Feriwala\'s gateway for a Conditional account, whatever its stock', function () {
        $this->account->forceFill(['account_type' => AccountType::Conditional])->save();
        websiteTestOwnsSelection($this->selection, 20);

        $order = partnerCheckoutPlace();

        expect(partnerCheckoutOwnership($order)['ownership'])->toBe(GatewayOwnership::Platform)
            ->and(partnerCheckoutFallbackReason($order))->toBe(PartnerGatewayFallbackReason::AccountNotNonConditional->value);
    });

    it('takes Feriwala\'s gateway for a line filled from the Partner\'s own stock', function () {
        websiteTestOwnsSelection($this->selection, 20);

        $order = partnerCheckoutPlace();

        expect(partnerCheckoutOwnership($order)['ownership'])->toBe(GatewayOwnership::Platform)
            ->and(partnerCheckoutFallbackReason($order))->toBe(PartnerGatewayFallbackReason::LineNotCentralStock->value)
            ->and(OrderPlatformReceivable::query()->count())->toBe(0);
    });

    it('refuses Model B for the whole order when only one line is ineligible', function () {
        $second = partnerCheckoutSelection(websiteTestProduct());
        websiteTestOwnsSelection($second, 20);
        StockItem::create([
            'warehouse_id' => Warehouse::query()->value('id'),
            'product_id' => $second->product_id,
            'available' => 20,
        ]);

        $order = partnerCheckoutPlace(lines: [[$this->selection, 1], [$second, 1]]);

        expect(partnerCheckoutOwnership($order)['ownership'])->toBe(GatewayOwnership::Platform)
            ->and(partnerCheckoutFallbackReason($order))->toBe(PartnerGatewayFallbackReason::LineNotCentralStock->value)
            ->and(OrderPlatformReceivable::query()->count())->toBe(0);
    });

    it('takes Feriwala\'s gateway, and calls nothing, when the receivable snapshot cannot be written', function () {
        $this->mock(FreezePlatformReceivable::class, fn ($mock) => $mock->shouldReceive('handle')->andThrow(PlatformReceivableRefused::noLines()));

        $order = partnerCheckoutPlace();

        expect(partnerCheckoutOwnership($order)['ownership'])->toBe(GatewayOwnership::Platform)
            ->and(partnerCheckoutFallbackReason($order))->toBe(PartnerGatewayFallbackReason::SnapshotIncomplete->value)
            ->and(OrderPlatformReceivable::query()->count())->toBe(0);

        Http::assertNothingSent();
    });

    it('records only a name from a fixed list, never a credential or a provider message', function () {
        partnerCheckoutEnable(enabled: false);

        $order = partnerCheckoutPlace();
        $entry = PaymentLog::query()->where('payment_id', $order->payment_id)->where('event', 'partner_gateway_not_used')->sole();

        expect($entry->context)->toBe(['reason' => 'feature_disabled', 'partner_provider' => 'sslcommerz'])
            ->and(json_encode($entry))->not->toContain('partner-secret-pass');
    });

    it('says nothing at all for a Storefront with no Partner gateway, or a cash order', function () {
        $cod = partnerCheckoutPlace('cod');

        expect($cod->payment->gateway_ownership)->toBeNull()
            ->and(PaymentLog::query()->where('event', 'partner_gateway_not_used')->count())->toBe(0);

        app(UsePlatformGateway::class)->handle($this->owner, $this->website);

        $platform = partnerCheckoutPlace();

        expect(partnerCheckoutOwnership($platform)['ownership'])->toBe(GatewayOwnership::Platform)
            ->and(PaymentLog::query()->where('event', 'partner_gateway_not_used')->count())->toBe(0);
    });

    it('leaves a Feriwala-gateway order exactly as it was, including who was paid', function () {
        app(UsePlatformGateway::class)->handle($this->owner, $this->website);

        $order = partnerCheckoutPlace();
        app(InitiateOrderPayment::class)->handle($order);

        expect(partnerCheckoutOwnership($order))->toMatchArray(['ownership' => GatewayOwnership::Platform, 'version' => null])
            ->and(partnerCheckoutSentStore('platform-store'))->toBeTrue()
            ->and(partnerCheckoutSentStore('partner-store'))->toBeFalse();
    });
});

describe('a Partner payment is never quietly moved', function () {
    it('stays the Partner\'s when the provider is down, and starts when it is back', function () {
        $order = partnerCheckoutPlace();
        $this->providerDown = true;

        expect(fn () => app(InitiateOrderPayment::class)->handle($order))->toThrow(GatewayUnavailable::class);

        $payment = $order->payment->refresh();

        expect($payment->gateway_ownership)->toBe(GatewayOwnership::Partner)
            ->and($payment->status)->toBe(PaymentStatus::Draft)
            ->and($order->refresh()->status)->toBe(OrderStatus::PaymentPending)
            ->and(partnerCheckoutSentStore('platform-store'))->toBeFalse();

        $this->providerDown = false;

        app(InitiateOrderPayment::class)->handle($order);

        expect($order->payment->refresh()->status)->toBe(PaymentStatus::Initiated)
            ->and($order->payment->partner_gateway_configuration_version_id)->not->toBeNull();
    });

    it('is never started when its receivable snapshot is missing', function () {
        $order = partnerCheckoutPlace();

        DB::statement('ALTER TABLE order_platform_receivables DISABLE TRIGGER order_platform_receivables_no_delete');
        DB::statement('ALTER TABLE order_platform_receivable_lines DISABLE TRIGGER order_platform_receivable_lines_no_delete');
        DB::table('order_platform_receivable_lines')->delete();
        DB::table('order_platform_receivables')->delete();

        expect(fn () => app(InitiateOrderPayment::class)->handle($order))->toThrow(OrderRefused::class);

        Http::assertNothingSent();
        expect($order->payment->refresh()->gateway_ownership)->toBe(GatewayOwnership::Partner);
    });

    it('cannot have its payment method changed', function () {
        $order = partnerCheckoutPlace();

        expect(fn () => app(ChangeOrderPaymentMethod::class)->handle($order, 'sslcommerz'))
            ->toThrow(OrderRefused::class);

        expect($order->payment->refresh()->gateway_ownership)->toBe(GatewayOwnership::Partner);
    });

    it('cannot be marked paid by hand: only the provider can say the Partner was paid', function () {
        $order = partnerCheckoutPlace();

        expect(fn () => app(SettlePaymentManually::class)->handle($order->payment, $this->admin, 'MANUAL-1', 'The Partner says so', true, true, true))
            ->toThrow(ManualSettlementRefused::class, 'Partner\'s own gateway');

        expect($order->payment->refresh()->status)->not->toBe(PaymentStatus::Paid);
    });

    it('is never confirmed into the normal flow by a confirmation of its own', function () {
        $order = partnerCheckoutPlace();
        $payment = $order->payment;
        $payment->transitionTo(PaymentStatus::Initiated)->save();
        $payment->transitionTo(PaymentStatus::Paid);
        $payment->forceFill(['completed_at' => now(), 'gateway_reference' => 'FORCED-1'])->save();

        app(ConfirmOrderPayment::class)->handle($payment->refresh());

        // Verified, paid — and still waiting: the receivable has not been collected.
        expect($order->refresh()->status)->toBe(OrderStatus::PaymentPending);
    });
});
