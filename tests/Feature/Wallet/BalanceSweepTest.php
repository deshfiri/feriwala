<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Wallet\Actions\CaptureDepositObligation;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Actions\SweepWalletBalances;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletBalanceState;
use App\Domain\Wallet\Models\DepositRule;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletRestriction;
use App\Domain\Wallet\WalletService;
use App\Notifications\Wallet\WalletBalanceLow;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Rules\RuleScope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;

/*
 * The daily pass (P2-20, §24.3, §41).
 *
 * Balances do not fall below a line by being written to — they fall below it
 * because a deadline arrived while nobody was looking. So something has to come
 * round and ask, and everything it does has to be safe to do again tomorrow.
 */

beforeEach(function () {
    Notification::fake();

    DepositRule::create([
        'scope' => RuleScope::Global,
        'required_initial_deposit' => Money::zero(),
        'minimum_balance' => Money::fromDecimal('2000.00'),
        'restricts_chargeable_services' => true,
        'currency_code' => 'BDT',
        'effective_from' => CarbonImmutable::now()->subMonth(),
        'is_active' => true,
    ]);
});

function sweptWallet(string $credit): Wallet
{
    $account = testBusinessAccount(AccountStatus::Active);
    $wallet = app(OpenWallet::class)->handle($account);
    $amount = Money::fromDecimal($credit, Currency::BDT);

    if ($amount->isPositive()) {
        app(WalletService::class)->credit(
            $wallet,
            LedgerTransactionType::TopUpCredit,
            $amount,
            new PostingContext(source: 'test', description: 'Opening'),
        );
    }

    app(CaptureDepositObligation::class)->handle($account);

    return $wallet->refresh();
}

it('checks every wallet there is', function () {
    sweptWallet('5000.00');
    sweptWallet('1000.00');
    sweptWallet('0.00');

    $result = app(SweepWalletBalances::class)->handle();

    expect($result['checked'])->toBe(3)
        ->and($result['restricted'])->toBe(2)
        ->and($result['failed'])->toBe(0);
});

it('leaves a healthy wallet alone', function () {
    $wallet = sweptWallet('5000.00');

    app(SweepWalletBalances::class)->handle();

    expect($wallet->refresh()->balance_state)->toBe(WalletBalanceState::Healthy)
        ->and(WalletRestriction::query()->count())->toBe(0);

    Notification::assertNothingSent();
});

it('restricts one that has fallen short', function () {
    $wallet = sweptWallet('1000.00');

    app(SweepWalletBalances::class)->handle();

    expect($wallet->refresh()->balance_state)->toBe(WalletBalanceState::Critical)
        ->and(WalletRestriction::query()->standing()->count())->toBe(2);
});

it('does the same thing tomorrow and the day after', function () {
    /*
     * The sweep runs every morning and a shortfall lasts until it is paid.
     * Everything it does has to be safe to repeat: no second restriction, no
     * second message, no deadline that quietly moves.
     */
    $wallet = sweptWallet('1000.00');

    app(SweepWalletBalances::class)->handle();
    $deadline = $wallet->refresh()->grace_ends_at;

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDay());
    app(SweepWalletBalances::class)->handle();

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDay());
    app(SweepWalletBalances::class)->handle();

    expect(WalletRestriction::query()->count())->toBe(2)
        ->and($wallet->refresh()->grace_ends_at?->toIso8601String())
        ->toBe($deadline?->toIso8601String());

    Notification::assertSentToTimes(
        $wallet->businessAccount->owner,
        WalletBalanceLow::class,
        1,
    );

    CarbonImmutable::setTestNow();
});

it('gives services back before it considers taking more away', function () {
    /*
     * Order matters. An account that paid last night should have its services
     * back before anything else looks at it — the other way round, a wallet
     * briefly reaches a stage it had already paid its way out of.
     */
    $wallet = sweptWallet('1000.00');

    app(SweepWalletBalances::class)->handle();

    expect(WalletRestriction::query()->standing()->count())->toBe(2);

    app(WalletService::class)->credit(
        $wallet->refresh(),
        LedgerTransactionType::TopUpCredit,
        Money::fromDecimal('2000.00', Currency::BDT),
        new PostingContext(source: 'test', description: 'Paid up'),
    );

    $result = app(SweepWalletBalances::class)->handle();

    expect($result['restored'])->toBe(1)
        ->and($result['restricted'])->toBe(0)
        ->and(WalletRestriction::query()->standing()->count())->toBe(0)
        ->and($wallet->refresh()->balance_state)->toBe(WalletBalanceState::Healthy);
});

it('keeps going when one wallet cannot be handled', function () {
    // A thousand accounts should not go unchecked because one of them has a
    // problem.
    $broken = sweptWallet('1000.00');
    sweptWallet('1000.00');

    // A currency the Money value object will refuse to reason about.
    $broken->forceFill(['currency_code' => 'XXX'])->saveQuietly();

    $result = app(SweepWalletBalances::class)->handle();

    expect($result['checked'])->toBe(2)
        ->and($result['failed'])->toBe(1)
        ->and($result['restricted'])->toBe(1);
});

it('counts how many are short without changing anything', function () {
    sweptWallet('1000.00');
    sweptWallet('5000.00');

    app(SweepWalletBalances::class)->handle();

    expect(app(SweepWalletBalances::class)->shortCount())->toBe(1);
});

it('is on the schedule, guarded against running twice at once', function () {
    /*
     * §41. This pass restricts accounts and sends messages; two application
     * servers running it at once would mean two texts for one shortfall.
     */
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => $event->description === 'Check every wallet against what its account must hold (§24.3)');

    expect($events)->toHaveCount(1);

    $event = $events->first();

    expect($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expression)->toBe('30 2 * * *');
});
