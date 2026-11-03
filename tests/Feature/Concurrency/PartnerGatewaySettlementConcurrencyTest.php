<?php

use App\Domain\Billing\Actions\ReconcileGatewayPayments;
use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Events\PartnerGatewayPaymentVerified;
use App\Domain\Billing\Jobs\SettleGatewayNotification;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Order\Actions\InitiateOrderPayment;
use App\Domain\Order\Enums\CollectionChannel;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderProceedsSettlement;
use App\Domain\Wallet\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * Real-process races for a payment made to a Partner's own gateway (D36): workers
 * forked to settle the same payment on a connection whose writes actually commit,
 * then asked afterwards what happened — the same shape as the other concurrency
 * tests.
 *
 * Covered: the provider's notification arriving several times at once, and a
 * notification racing the reconciliation sweep. However they interleave, the
 * payment is verified once, one collection fact is recorded, one announcement is
 * made, and no Wallet is touched.
 */
beforeEach(function () {
    config()->set('database.connections.partner_gateway_race', config('database.connections.pgsql'));
    config()->set('database.default', 'partner_gateway_race');

    partnerCheckoutWorld();

    // These races are about verifying the payment. What happens to Banij's share
    // afterwards is the collection's business, and has its own races.
    Event::fake([PartnerGatewayPaymentVerified::class]);
});

afterEach(function () {
    // What the race committed outlives the test. The settlements refuse deletion
    // by design, and this is the one place allowed to reach past that.
    DB::statement('ALTER TABLE order_proceeds_settlements DISABLE TRIGGER order_proceeds_settlements_kept');
    DB::table('order_proceeds_settlements')->where('business_account_id', $this->account->id)->delete();
    DB::statement('ALTER TABLE order_proceeds_settlements ENABLE TRIGGER order_proceeds_settlements_kept');
});

/**
 * Run one piece of work in `$count` real processes, started together.
 */
function partnerGatewayRace(int $count, Closure $work): void
{
    DB::purge('partner_gateway_race');

    $startAt = microtime(true) + 0.3;
    $pids = [];

    for ($worker = 0; $worker < $count; $worker++) {
        $pid = pcntl_fork();

        expect($pid)->not->toBe(-1);

        if ($pid === 0) {
            usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));

            try {
                $work($worker);
            } catch (Throwable) {
                // Losing the race is an expected outcome for one side of these.
                // What happened is read back from the database afterwards.
            }

            posix_kill(posix_getpid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
}

/**
 * A Partner order started and paid at the provider, not yet reported to us.
 *
 * @return array{0: Order, 1: Payment, 2: string}
 */
function partnerGatewayRaceOrder(): array
{
    $order = partnerCheckoutPlace();
    app(InitiateOrderPayment::class)->handle($order);
    $payment = $order->payment()->first();

    return [$order, $payment, partnerCheckoutPays($payment)];
}

/**
 * What the database holds once the dust has settled.
 */
function partnerGatewayRaceOutcome(Order $order, Payment $payment, string $valId): void
{
    $payment = Payment::query()->findOrFail($payment->id);
    $settlement = OrderProceedsSettlement::query()->where('order_id', $order->id)->sole();

    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->gateway_reference)->toBe($valId)
        ->and($payment->gateway_settlement_reference)->not->toBeNull()
        // The Partner was paid; the order is still waiting for Banij's receivable.
        ->and(Order::query()->findOrFail($order->id)->status)->toBe(OrderStatus::PaymentPending)
        // One collection fact, recorded for reporting, crediting nothing.
        ->and($settlement->collection_channel)->toBe(CollectionChannel::PartnerGatewayExternal)
        ->and($settlement->wallet_transaction_id)->toBeNull()
        // Announced once, and no Wallet was touched.
        ->and(PaymentLog::query()->where('payment_id', $payment->id)->where('outcome', 'partner_payment_verified')->count())->toBe(1)
        ->and(LedgerEntry::query()->count())->toBe(0);
}

it('verifies a Partner payment once when the provider\'s notification arrives several times at once', function () {
    [$order, $payment, $valId] = partnerGatewayRaceOrder();

    partnerGatewayRace(6, fn () => SettleGatewayNotification::dispatchSync($payment->id, $valId, 'ipn'));

    partnerGatewayRaceOutcome($order, $payment, $valId);
});

it('verifies a Partner payment once when a notification races the reconciliation sweep', function () {
    [$order, $payment, $valId] = partnerGatewayRaceOrder();

    partnerGatewayRace(6, function (int $worker) use ($payment, $valId) {
        if ($worker % 2 === 0) {
            app(SettlePayment::class)->handle(Payment::query()->findOrFail($payment->id), $valId);

            return;
        }

        app(ReconcileGatewayPayments::class)->check(Payment::query()->findOrFail($payment->id));
    });

    partnerGatewayRaceOutcome($order, $payment, $valId);
});
