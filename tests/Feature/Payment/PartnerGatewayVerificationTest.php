<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Actions\ReconcileGatewayPayments;
use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Actions\UsePlatformGateway;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Events\PartnerGatewayPaymentVerified;
use App\Domain\Billing\Jobs\SettleGatewayNotification;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Order\Actions\InitiateOrderPayment;
use App\Domain\Order\Actions\SynchronizeOrderProceedsWithDelivery;
use App\Domain\Order\Enums\CollectionChannel;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderProceedsSettlement;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/*
 * Verifying a payment made to a Partner's own gateway (D36): the provider is asked
 * with the credentials the payment was started with, nothing a browser says is
 * believed, and verifying proves the Partner was paid without releasing the order.
 */

beforeEach(function () {
    partnerCheckoutWorld();
});

/**
 * A Partner order that is started and paid at the provider, but not yet reported to us.
 *
 * @return array{0: Order, 1: Payment, 2: string} the order, its payment, the provider's transaction id
 */
function partnerVerifyPaidAtProvider(array $providerOverrides = []): array
{
    $order = partnerCheckoutPlace();
    app(InitiateOrderPayment::class)->handle($order);
    $payment = $order->payment->refresh();

    return [$order, $payment, partnerCheckoutPays($payment, $providerOverrides)];
}

/**
 * The provider's notification, signed with `$password`, to `$endpoint` or the Partner's own address.
 */
function partnerVerifyNotify(Payment $payment, string $valId, string $password = 'partner-secret-pass', ?string $endpoint = null): TestResponse
{
    $fields = ['tran_id' => $payment->reference, 'val_id' => $valId, 'status' => 'VALID'];

    return test()->post(
        $endpoint ?? route('webhooks.partner-payment', ['sslcommerz', $payment->public_id]),
        partnerCheckoutSigned($fields, $password),
    );
}

function partnerVerifyValidationRequests(): int
{
    return Http::recorded()->filter(fn (array $pair) => str_contains($pair[0]->url(), 'validationserverAPI'))->count();
}

function partnerVerifySettlements(Order $order): int
{
    return OrderProceedsSettlement::query()->where('order_id', $order->id)->count();
}

/**
 * Old enough for the reconciliation sweep to look at it.
 */
function partnerVerifyAged(Payment $payment): void
{
    DB::table('payments')->where('id', $payment->id)->update(['created_at' => now()->subMinutes(10)]);
}

describe('a verified Partner payment', function () {
    it('is paid once the provider confirms it, and nothing else moves', function () {
        Event::fake([PartnerGatewayPaymentVerified::class]);
        Notification::fake();
        [$order, $payment, $valId] = partnerVerifyPaidAtProvider();

        partnerVerifyNotify($payment, $valId)->assertOk();

        $payment->refresh();
        $settlement = OrderProceedsSettlement::query()->where('order_id', $order->id)->sole();

        expect($payment->status)->toBe(PaymentStatus::Paid)
            ->and($payment->gateway_reference)->toBe($valId)
            ->and($payment->completed_at)->not->toBeNull()
            // Paid to the Partner, and still waiting: Banij's receivable comes first.
            ->and($order->refresh()->status)->toBe(OrderStatus::PaymentPending)
            ->and($order->paid_at)->toBeNull()
            ->and($order->items()->sole()->stockReservation->status->value)->toBe('active')
            // Collected by the Partner, for reporting only.
            ->and($settlement->collection_channel)->toBe(CollectionChannel::PartnerGatewayExternal)
            ->and($settlement->payment_collected_amount->toDecimal())->toBe('5200.00')
            ->and($settlement->wallet_transaction_id)->toBeNull()
            ->and($settlement->eligible_at)->toBeNull()
            // No profit credit and no Wallet movement of any kind.
            ->and(LedgerEntry::query()->count())->toBe(0)
            ->and(Wallet::query()->where('business_account_id', $this->account->id)->count())->toBe(0);

        Event::assertDispatchedTimes(PartnerGatewayPaymentVerified::class, 1);
        Event::assertDispatched(PartnerGatewayPaymentVerified::class, fn ($event) => $event->paymentId === $payment->id && $event->orderId === $order->id);
        // Nobody is told the money "landed": Feriwala did not receive it.
        Notification::assertNothingSent();
    });

    it('is checked with the Partner\'s own credentials, never Feriwala\'s', function () {
        [, $payment, $valId] = partnerVerifyPaidAtProvider();

        partnerVerifyNotify($payment, $valId)->assertOk();

        expect(partnerCheckoutSentStore('partner-store'))->toBeTrue()
            ->and(partnerCheckoutSentStore('platform-store'))->toBeFalse();
    });

    it('earns no wallet profit even when the order is later delivered', function () {
        [$order, $payment, $valId] = partnerVerifyPaidAtProvider();
        partnerVerifyNotify($payment, $valId)->assertOk();

        app(SynchronizeOrderProceedsWithDelivery::class)->handle($order->refresh()->load('items', 'payment'), OrderDeliveryStatus::Delivered);

        $settlement = OrderProceedsSettlement::query()->where('order_id', $order->id)->sole();

        expect($settlement->eligible_at)->not->toBeNull()
            ->and($settlement->reseller_earning->toDecimal())->toBe('0.00')
            ->and($settlement->wallet_transaction_id)->toBeNull()
            ->and(LedgerEntry::query()->count())->toBe(0);
    });

    it('is verified once however many times the provider tells us', function () {
        Event::fake([PartnerGatewayPaymentVerified::class]);
        [$order, $payment, $valId] = partnerVerifyPaidAtProvider();

        partnerVerifyNotify($payment, $valId)->assertOk();
        partnerVerifyNotify($payment, $valId)->assertOk();
        partnerVerifyNotify($payment, $valId)->assertOk();

        expect(partnerVerifyValidationRequests())->toBe(1)
            ->and(partnerVerifySettlements($order))->toBe(1)
            ->and(PaymentLog::query()->where('payment_id', $payment->id)->where('outcome', 'partner_payment_verified')->count())->toBe(1);

        Event::assertDispatchedTimes(PartnerGatewayPaymentVerified::class, 1);
    });
});

describe('what is checked before a payment is believed', function () {
    it('does not settle a different amount', function () {
        [$order, $payment, $valId] = partnerVerifyPaidAtProvider(['amount' => '5000.00']);

        partnerVerifyNotify($payment, $valId)->assertOk();

        expect($payment->refresh()->status)->not->toBe(PaymentStatus::Paid)
            ->and($order->refresh()->status)->not->toBe(OrderStatus::Paid)
            ->and(partnerVerifySettlements($order))->toBe(0);
    });

    it('does not settle a different currency', function () {
        [$order, $payment, $valId] = partnerVerifyPaidAtProvider(['currency' => 'USD']);

        partnerVerifyNotify($payment, $valId)->assertOk();

        expect($payment->refresh()->status)->not->toBe(PaymentStatus::Paid)
            ->and(partnerVerifySettlements($order))->toBe(0);
    });

    it('does not settle a transaction that names another payment', function () {
        [$order, $payment, $valId] = partnerVerifyPaidAtProvider(['tran_id' => 'PAY-SOMEONE-ELSE']);

        partnerVerifyNotify($payment, $valId)->assertOk();

        expect($payment->refresh()->status)->not->toBe(PaymentStatus::Paid)
            ->and(partnerVerifySettlements($order))->toBe(0);
    });

    it('does not settle a transaction the provider has never heard of', function () {
        [$order, $payment] = partnerVerifyPaidAtProvider();

        partnerVerifyNotify($payment, 'VAL-NEVER-SEEN')->assertOk();

        expect($payment->refresh()->status)->not->toBe(PaymentStatus::Paid)
            ->and(partnerVerifySettlements($order))->toBe(0);
    });

    it('refuses a provider settlement a Feriwala-gateway payment already holds', function () {
        [$orderA, $paymentA] = partnerVerifyPaidAtProvider();

        // The Storefront switches back; a second order takes Feriwala's own gateway.
        app(UsePlatformGateway::class)->handle($this->owner, $this->website);
        $orderB = partnerCheckoutPlace();
        app(InitiateOrderPayment::class)->handle($orderB);
        $paymentB = $orderB->payment->refresh();
        $valB = partnerCheckoutPays($paymentB, ['bank_tran_id' => 'SHARED-BANK-1']);

        partnerVerifyNotify($paymentB, $valB, 'platform-pass', route('webhooks.payment', 'sslcommerz'))->assertOk();

        expect($paymentB->refresh()->status)->toBe(PaymentStatus::Paid)
            ->and($paymentB->gateway_settlement_reference)->toBe('SHARED-BANK-1');

        // The Partner's own provider reports the very same bank transaction.
        $valA = partnerCheckoutPays($paymentA, ['bank_tran_id' => 'SHARED-BANK-1']);
        partnerVerifyNotify($paymentA, $valA)->assertOk();

        expect($paymentA->refresh()->status)->not->toBe(PaymentStatus::Paid)
            ->and(partnerVerifySettlements($orderA))->toBe(0);
    });

    it('cannot hold a provider transaction id another payment holds, whoever owns either', function () {
        [, $paymentA, $valA] = partnerVerifyPaidAtProvider();
        partnerVerifyNotify($paymentA, $valA)->assertOk();

        app(UsePlatformGateway::class)->handle($this->owner, $this->website);
        $orderB = partnerCheckoutPlace();

        expect(fn () => DB::transaction(fn () => DB::table('payments')->where('id', $orderB->payment_id)->update(['gateway_reference' => $valA])))
            ->toThrow(QueryException::class);
    });
});

describe('the provider\'s notification', function () {
    it('is refused when it is signed with anybody else\'s password', function () {
        [, $payment, $valId] = partnerVerifyPaidAtProvider();

        partnerVerifyNotify($payment, $valId, 'platform-pass')->assertUnauthorized();

        expect($payment->refresh()->status)->not->toBe(PaymentStatus::Paid)
            ->and(partnerVerifyValidationRequests())->toBe(0)
            ->and(PaymentLog::query()->where('outcome', 'refused_signature')->exists())->toBeTrue();
    });

    it('is refused when it carries no signature at all', function () {
        [, $payment, $valId] = partnerVerifyPaidAtProvider();

        $this->post(route('webhooks.partner-payment', ['sslcommerz', $payment->public_id]), [
            'tran_id' => $payment->reference, 'val_id' => $valId, 'status' => 'VALID',
        ])->assertUnauthorized();

        expect($payment->refresh()->status)->not->toBe(PaymentStatus::Paid);
    });

    it('is not accepted on Feriwala\'s own address for a Partner payment, even when signed there', function () {
        [, $payment, $valId] = partnerVerifyPaidAtProvider();

        partnerVerifyNotify($payment, $valId, 'platform-pass', route('webhooks.payment', 'sslcommerz'))->assertOk();

        expect($payment->refresh()->status)->not->toBe(PaymentStatus::Paid)
            ->and(partnerVerifyValidationRequests())->toBe(0)
            ->and(PaymentLog::query()->where('payment_id', $payment->id)->where('outcome', 'partner_payment_on_platform_endpoint')->exists())->toBeTrue();
    });

    it('is answered as an unknown gateway for a payment that is not a Partner\'s, or of another gateway', function () {
        [, $payment, $valId] = partnerVerifyPaidAtProvider();
        $signed = partnerCheckoutSigned(['tran_id' => $payment->reference, 'val_id' => $valId, 'status' => 'VALID'], 'partner-secret-pass');

        $this->post(route('webhooks.partner-payment', ['sslcommerz', 'NOT-A-PAYMENT']), $signed)->assertNotFound();
        $this->post(route('webhooks.partner-payment', ['stripe', $payment->public_id]), $signed)->assertNotFound();

        app(UsePlatformGateway::class)->handle($this->owner, $this->website);
        $platform = partnerCheckoutPlace();

        $this->post(route('webhooks.partner-payment', ['sslcommerz', $platform->payment->public_id]), $signed)->assertNotFound();
    });

    it('is still checked with the original credentials after they are rotated and the feature is off', function () {
        [, $payment, $valId] = partnerVerifyPaidAtProvider();

        partnerCheckoutConfigure('rotated-store', 'rotated-pass');
        app(UsePlatformGateway::class)->handle($this->owner, $this->website);
        partnerCheckoutEnable(enabled: false);

        // Signed with the new password: refused. Signed with the one it started with: verified.
        partnerVerifyNotify($payment, $valId, 'rotated-pass')->assertUnauthorized();
        partnerVerifyNotify($payment, $valId, 'partner-secret-pass')->assertOk();

        expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
            ->and(partnerCheckoutSentStore('rotated-store'))->toBeFalse();
    });
});

describe('the customer\'s browser coming back', function () {
    it('settles nothing when the return is forged', function () {
        [$order, $payment] = partnerVerifyPaidAtProvider();

        $this->post(route('website-orders.payment.return', $order->public_id), [
            'tran_id' => $payment->reference, 'val_id' => 'VAL-FORGED', 'status' => 'VALID',
        ])->assertRedirect();

        expect($payment->refresh()->status)->not->toBe(PaymentStatus::Paid)
            ->and(partnerVerifyValidationRequests())->toBe(0);
    });

    it('settles nothing when the forger signs it with Feriwala\'s own password', function () {
        [$order, $payment, $valId] = partnerVerifyPaidAtProvider();

        $this->post(
            route('website-orders.payment.return', $order->public_id),
            partnerCheckoutSigned(['tran_id' => $payment->reference, 'val_id' => $valId, 'status' => 'VALID'], 'platform-pass'),
        )->assertRedirect();

        expect($payment->refresh()->status)->not->toBe(PaymentStatus::Paid)
            ->and(partnerVerifyValidationRequests())->toBe(0);
    });

    it('only asks the provider, and the provider\'s answer is what settles', function () {
        [$order, $payment, $valId] = partnerVerifyPaidAtProvider();

        $this->post(
            route('website-orders.payment.return', $order->public_id),
            partnerCheckoutSigned(['tran_id' => $payment->reference, 'val_id' => $valId, 'status' => 'VALID'], 'partner-secret-pass'),
        )->assertRedirect();

        expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
            ->and(partnerVerifyValidationRequests())->toBe(1)
            ->and(partnerCheckoutSentStore('partner-store'))->toBeTrue();
    });

    it('settles once however often the browser returns', function () {
        Event::fake([PartnerGatewayPaymentVerified::class]);
        [$order, $payment, $valId] = partnerVerifyPaidAtProvider();
        $body = partnerCheckoutSigned(['tran_id' => $payment->reference, 'val_id' => $valId, 'status' => 'VALID'], 'partner-secret-pass');

        $this->post(route('website-orders.payment.return', $order->public_id), $body)->assertRedirect();
        $this->post(route('website-orders.payment.return', $order->public_id), $body)->assertRedirect();
        $this->post(route('website-orders.payment.return', $order->public_id), $body)->assertRedirect();

        expect(partnerVerifyValidationRequests())->toBe(1)
            ->and(partnerVerifySettlements($order))->toBe(1);

        Event::assertDispatchedTimes(PartnerGatewayPaymentVerified::class, 1);
    });
});

describe('reconciliation', function () {
    it('finds a payment the provider confirmed and nobody told us about', function () {
        [$order, $payment] = partnerVerifyPaidAtProvider();
        partnerVerifyAged($payment);

        $first = app(ReconcileGatewayPayments::class)->handle();
        $second = app(ReconcileGatewayPayments::class)->handle();

        expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
            ->and($first['checked'])->toBeGreaterThanOrEqual(1)
            ->and($second['settled'])->toBe(0)
            ->and(partnerVerifySettlements($order))->toBe(1)
            ->and(partnerCheckoutSentStore('partner-store'))->toBeTrue()
            ->and(partnerCheckoutSentStore('platform-store'))->toBeFalse();
    });

    it('still finds it after the configuration is rotated and switched off', function () {
        [, $payment] = partnerVerifyPaidAtProvider();
        partnerVerifyAged($payment);

        partnerCheckoutConfigure('rotated-store', 'rotated-pass');
        app(UsePlatformGateway::class)->handle($this->owner, $this->website);
        partnerCheckoutEnable(enabled: false);

        app(ReconcileGatewayPayments::class)->handle();

        expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
            ->and(partnerCheckoutSentStore('rotated-store'))->toBeFalse();
    });

    it('settles once when a notification and a sweep both arrive', function () {
        Event::fake([PartnerGatewayPaymentVerified::class]);
        [$order, $payment, $valId] = partnerVerifyPaidAtProvider();
        partnerVerifyAged($payment);

        partnerVerifyNotify($payment, $valId)->assertOk();
        app(ReconcileGatewayPayments::class)->handle();

        expect(partnerVerifySettlements($order))->toBe(1);

        Event::assertDispatchedTimes(PartnerGatewayPaymentVerified::class, 1);
    });
});

describe('Feriwala\'s own gateway is unchanged', function () {
    it('still confirms the order and credits nothing until delivery', function () {
        app(UsePlatformGateway::class)->handle($this->owner, $this->website);
        $order = partnerCheckoutPlace();
        app(InitiateOrderPayment::class)->handle($order);
        $payment = $order->payment->refresh();
        $valId = partnerCheckoutPays($payment);

        partnerVerifyNotify($payment, $valId, 'platform-pass', route('webhooks.payment', 'sslcommerz'))->assertOk();

        expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
            ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
            ->and(OrderProceedsSettlement::query()->where('order_id', $order->id)->sole()->collection_channel)->toBe(CollectionChannel::PlatformGateway)
            ->and(partnerCheckoutSentStore('platform-store'))->toBeTrue()
            ->and(partnerCheckoutSentStore('partner-store'))->toBeFalse();
    });
});

describe('secrets', function () {
    it('never reach a log, an audit entry, a payment log, a queued job or an exception', function () {
        $logs = partnerCheckoutCaptureLogs();
        $secrets = ['partner-secret-pass', md5('partner-secret-pass')];

        // The provider is down when the customer first tries.
        $order = partnerCheckoutPlace();
        $this->providerDown = true;

        try {
            app(InitiateOrderPayment::class)->handle($order);
            $this->fail('The provider was down.');
        } catch (GatewayUnavailable $exception) {
            $thrown = $exception->getMessage();
        }

        $this->providerDown = false;
        app(InitiateOrderPayment::class)->handle($order);
        $payment = $order->payment->refresh();
        $valId = partnerCheckoutPays($payment);

        // A forged notification, a forged return, then the real ones, then a sweep.
        $this->post(route('webhooks.partner-payment', ['sslcommerz', $payment->public_id]), ['tran_id' => $payment->reference, 'val_id' => $valId]);
        $this->post(route('website-orders.payment.return', $order->public_id), ['tran_id' => $payment->reference, 'val_id' => 'FORGED']);
        partnerVerifyNotify($payment, $valId)->assertOk();
        partnerVerifyAged($payment);
        app(ReconcileGatewayPayments::class)->handle();

        $surfaces = [
            'exception' => $thrown,
            'logs' => partnerCheckoutLogText($logs),
            'payment logs' => PaymentLog::query()->get()->toJson(),
            'audit' => AuditLog::query()->get()->toJson(),
            'payments' => Payment::query()->get()->toJson(),
            'orders' => Order::query()->get()->toJson(),
            'queued job' => serialize(new SettleGatewayNotification($payment->id, $valId, 'ipn')),
        ];

        foreach ($surfaces as $name => $text) {
            foreach ($secrets as $secret) {
                expect($text)->not->toContain($secret, "{$name} leaked a credential");
            }
        }

        expect($surfaces['payment logs'])->not->toContain('partner-store')
            ->and($surfaces['audit'])->not->toContain('partner-store');
    });

    it('are not what the provider\'s transport errors say, when its server is unreachable', function () {
        [, $payment, $valId] = partnerVerifyPaidAtProvider();

        Http::fake(fn () => throw new ConnectionException('cURL error 7: could not connect to https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php?store_id=partner-store&store_passwd=partner-secret-pass'));

        try {
            app(SettlePayment::class)->handle($payment, $valId);
            $this->fail('The provider was unreachable.');
        } catch (GatewayUnavailable $exception) {
            expect($exception->getMessage())->not->toContain('partner-secret-pass')
                ->and($exception->getMessage())->not->toContain('partner-store')
                ->and($exception->getPrevious())->toBeNull();
        }
    });
});
