<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Wallet\Actions\CaptureDepositObligation;
use App\Domain\Wallet\Actions\EnforceBalanceRules;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Actions\RestoreWalletServices;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletRestrictionStage;
use App\Domain\Wallet\Models\DepositRule;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletRestriction;
use App\Domain\Wallet\WalletService;
use App\Notifications\Wallet\WalletBalanceLow;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Rules\RuleScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/*
 * §24.3's graded response, and putting it back (P2-16, P2-17).
 *
 * The order is the substance of it: told, then given the time §24.1 allotted,
 * then losing the things that can be lost without losing the business, and only
 * at the end the business itself.
 */

beforeEach(function () {
    Notification::fake();

    $this->account = testBusinessAccount(AccountStatus::Active);
});

/**
 * @param  array<string, mixed>  $rule
 */
function enforcementWallet(int $credit, array $rule = []): Wallet
{
    DepositRule::create([
        'scope' => RuleScope::Global,
        'required_initial_deposit_minor' => 0,
        'minimum_balance_minor' => 200000,
        'currency_code' => 'BDT',
        'effective_from' => CarbonImmutable::now()->subMonth(),
        'is_active' => true,
        ...$rule,
    ]);

    $wallet = app(OpenWallet::class)->handle(test()->account);

    if ($credit > 0) {
        app(WalletService::class)->credit(
            $wallet,
            LedgerTransactionType::TopUpCredit,
            Money::of($credit, Currency::BDT),
            new PostingContext(source: 'test', description: 'Opening'),
        );
    }

    app(CaptureDepositObligation::class)->handle(test()->account);

    return $wallet->refresh();
}

function topUp(Wallet $wallet, int $amount): void
{
    app(WalletService::class)->credit(
        $wallet->refresh(),
        LedgerTransactionType::TopUpCredit,
        Money::of($amount, Currency::BDT),
        new PostingContext(source: 'test', description: 'Top-up'),
    );
}

describe('the order of things', function () {
    it('tells the account and takes nothing away', function () {
        // §24.3 opens with the notice. Nothing is restricted for being told.
        $wallet = enforcementWallet(100000, ['restricts_account' => true, 'grace_period_days' => 14]);

        $applied = app(EnforceBalanceRules::class)->handle($wallet);

        expect($applied)->toBe([WalletRestrictionStage::Notified])
            ->and($this->account->fresh()->status)->toBe(AccountStatus::LowWalletBalance);

        Notification::assertSentTo($this->account->owner, WalletBalanceLow::class);
    });

    it('takes nothing away while the grace period is running', function () {
        /*
         * The point of a grace period. Acting during it would make the
         * configured window decorative.
         */
        $wallet = enforcementWallet(100000, [
            'restricts_chargeable_services' => true,
            'restricts_account' => true,
            'grace_period_days' => 14,
        ]);

        app(EnforceBalanceRules::class)->handle($wallet);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(7));

        $applied = app(EnforceBalanceRules::class)->handle($wallet->refresh());

        expect($applied)->toBe([])
            ->and(WalletRestriction::query()->standing()->count())->toBe(1);

        CarbonImmutable::setTestNow();
    });

    it('starts taking things away once the time runs out', function () {
        $wallet = enforcementWallet(100000, [
            'restricts_chargeable_services' => true,
            'pauses_website_setup' => true,
            'grace_period_days' => 14,
        ]);

        app(EnforceBalanceRules::class)->handle($wallet);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(15));

        $applied = app(EnforceBalanceRules::class)->handle($wallet->refresh());

        expect($applied)->toBe([
            WalletRestrictionStage::ServicesRestricted,
            WalletRestrictionStage::WebsiteSetupPaused,
        ]);

        CarbonImmutable::setTestNow();
    });

    it('applies only the stages the rule authorises', function () {
        // A platform that only ever warns is a legitimate configuration.
        $wallet = enforcementWallet(100000);

        app(EnforceBalanceRules::class)->handle($wallet);
        $applied = app(EnforceBalanceRules::class)->handle($wallet->refresh());

        expect($applied)->toBe([])
            ->and(WalletRestriction::query()->standing()->count())->toBe(1)
            ->and(WalletRestriction::query()->standing()->first()->stage)
            ->toBe(WalletRestrictionStage::Notified);
    });

    it('disables an account only where that is configured', function () {
        $wallet = enforcementWallet(100000, ['disables_account' => true]);

        app(EnforceBalanceRules::class)->handle($wallet);

        expect($this->account->fresh()->status)->toBe(AccountStatus::TemporarilyDisabled)
            ->and(WalletRestriction::query()
                ->where('stage', WalletRestrictionStage::AccountDisabled)
                ->standing()
                ->exists())->toBeTrue();
    });

    it('does nothing at all to a healthy wallet', function () {
        $wallet = enforcementWallet(500000, ['restricts_account' => true]);

        expect(app(EnforceBalanceRules::class)->handle($wallet))->toBe([])
            ->and(WalletRestriction::query()->count())->toBe(0)
            ->and($this->account->fresh()->status)->toBe(AccountStatus::Active);

        Notification::assertNothingSent();
    });
});

describe('running it again', function () {
    it('restricts nothing twice', function () {
        /*
         * The sweep runs every morning and the shortfall lasts until it is
         * paid. Everything here has to be safe to repeat.
         */
        $wallet = enforcementWallet(100000, ['restricts_chargeable_services' => true]);

        app(EnforceBalanceRules::class)->handle($wallet);
        app(EnforceBalanceRules::class)->handle($wallet->refresh());
        app(EnforceBalanceRules::class)->handle($wallet->refresh());

        expect(WalletRestriction::query()->count())->toBe(2)
            ->and(WalletRestriction::query()->standing()->count())->toBe(2);
    });

    it('sends one message, not one a morning', function () {
        // Somebody texted daily about the same shortfall learns to ignore the
        // messages that matter.
        $wallet = enforcementWallet(100000);

        app(EnforceBalanceRules::class)->handle($wallet);
        app(EnforceBalanceRules::class)->handle($wallet->refresh());
        app(EnforceBalanceRules::class)->handle($wallet->refresh());

        Notification::assertSentToTimes($this->account->owner, WalletBalanceLow::class, 1);
    });

    it('writes the restriction to the audit log', function () {
        $wallet = enforcementWallet(100000);

        app(EnforceBalanceRules::class)->handle($wallet);

        expect(AuditLog::query()->where('action', 'wallet.restriction_applied')->count())->toBe(1);
    });
});

describe('restoring (P2-17)', function () {
    it('gives everything back when the balance is restored', function () {
        $wallet = enforcementWallet(100000, ['restricts_chargeable_services' => true]);

        app(EnforceBalanceRules::class)->handle($wallet);

        topUp($wallet, 200000);

        $lifted = app(RestoreWalletServices::class)->handle($wallet->refresh());

        expect($lifted)->toHaveCount(2)
            ->and(WalletRestriction::query()->standing()->count())->toBe(0)
            ->and($this->account->fresh()->status)->toBe(AccountStatus::Active);
    });

    it('puts the account back where it was, not simply to active', function () {
        /*
         * The difference between restoring an account and promoting one. This
         * account was on a renewal-due status before the balance took it; that
         * is where it goes back to.
         */
        $this->account->forceFill(['status' => AccountStatus::PackageRenewalDue])->save();

        $wallet = enforcementWallet(100000, ['disables_account' => true]);

        app(EnforceBalanceRules::class)->handle($wallet);

        expect($this->account->fresh()->status)->toBe(AccountStatus::TemporarilyDisabled);

        topUp($wallet, 200000);
        app(RestoreWalletServices::class)->handle($wallet->refresh());

        expect($this->account->fresh()->status)->toBe(AccountStatus::PackageRenewalDue);
    });

    it('gives nothing back on a partial top-up', function () {
        // §24.3 restores on *sufficient* top-up, and half of it is not
        // sufficient.
        $wallet = enforcementWallet(100000, ['restricts_chargeable_services' => true]);

        app(EnforceBalanceRules::class)->handle($wallet);

        topUp($wallet, 50000);

        expect(app(RestoreWalletServices::class)->handle($wallet->refresh()))->toBe([])
            ->and(WalletRestriction::query()->standing()->count())->toBe(2);
    });

    it('never lifts a restriction it did not place', function () {
        /*
         * The rule that makes restoration safe. An account restricted for a
         * failed review does not get its panel back by paying a deposit.
         */
        $wallet = enforcementWallet(500000);

        $foreign = WalletRestriction::create([
            'wallet_id' => $wallet->id,
            'business_account_id' => $this->account->id,
            'stage' => WalletRestrictionStage::AccountRestricted,
            'cause' => 'kyc_review',
            'previous_account_status' => AccountStatus::Active->value,
            'applied_account_status' => AccountStatus::TemporarilyRestricted->value,
            'started_at' => CarbonImmutable::now(),
        ]);

        app(RestoreWalletServices::class)->handle($wallet->refresh());

        expect($foreign->fresh()->isStanding())->toBeTrue();
    });

    it('leaves an account somebody else moved alone', function () {
        // Restoring a balance is not a reason to undo a decision this module
        // knows nothing about.
        $wallet = enforcementWallet(100000, ['disables_account' => true]);

        app(EnforceBalanceRules::class)->handle($wallet);

        $this->account->forceFill(['status' => AccountStatus::Suspended])->save();

        topUp($wallet, 200000);
        app(RestoreWalletServices::class)->handle($wallet->refresh());

        expect($this->account->fresh()->status)->toBe(AccountStatus::Suspended)
            // The restriction is still lifted — that one was ours.
            ->and(WalletRestriction::query()->fromLowBalance()->standing()->count())->toBe(0);
    });

    it('does nothing when nothing is standing', function () {
        $wallet = enforcementWallet(500000);

        expect(app(RestoreWalletServices::class)->handle($wallet))->toBe([]);
    });

    it('writes the restoration to the audit log', function () {
        $wallet = enforcementWallet(100000);

        app(EnforceBalanceRules::class)->handle($wallet);
        topUp($wallet, 200000);
        app(RestoreWalletServices::class)->handle($wallet->refresh());

        expect(AuditLog::query()->where('action', 'wallet.restriction_lifted')->count())->toBe(1);
    });
});
