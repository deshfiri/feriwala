<?php

namespace App\Domain\Wallet\Actions;

use App\Domain\Account\Actions\ChangeAccountStatus;
use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Wallet\AccountStatusRoute;
use App\Domain\Wallet\Enums\WalletBalanceState;
use App\Domain\Wallet\Enums\WalletRestrictionStage;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletDepositObligation;
use App\Domain\Wallet\Models\WalletRestriction;
use App\Notifications\Wallet\WalletBalanceLow;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Log\LogManager;
use Throwable;

/**
 * §24.3's graded response to a wallet that has fallen short.
 *
 * The order is the substance of it. An account is **told**, then given the time
 * §24.1 allotted it, and only when that runs out does it start losing things —
 * chargeable services first, the business itself last. Going straight to the end
 * because a balance dipped would be the opposite of what §24.3 describes.
 *
 * Every stage is optional and configured per rule, read from the obligation the
 * account was **captured** with rather than from the rule table. A platform that
 * only ever warns is a legitimate configuration.
 *
 * Nothing here is applied twice. A partial unique index allows one live
 * restriction per stage per wallet, so a sweep running every morning finds the
 * work already done and writes nothing — including the notification, which is
 * sent when a stage is first reached rather than every time it is still true.
 * Somebody texted daily about the same shortfall learns to ignore the messages
 * that matter.
 *
 * A notification that fails never rolls back a restriction, and a restriction
 * that fails never rolls back the wallet. The balance is the fact; everything
 * here is a consequence of it.
 */
class EnforceBalanceRules
{
    public function __construct(
        protected EvaluateWalletBalance $evaluate,
        protected ChangeAccountStatus $changeStatus,
        protected AccountStatusRoute $route,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
        protected LogManager $log,
    ) {}

    /**
     * Apply whatever §24.3 calls for, and nothing more.
     *
     * @return array<int, WalletRestrictionStage> the stages applied by this run
     */
    public function handle(Wallet $wallet, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();

        $state = $this->evaluate->handle($wallet, $at);

        if ($state === WalletBalanceState::Healthy) {
            // Nothing to do here. Putting things back is P2-17's job, and it
            // has its own reasons to be careful.
            return [];
        }

        $applied = [];

        // Told first, whether low or critical. §24.3 opens with the notice, and
        // an account that is merely low never gets past it.
        if ($this->apply($wallet, WalletRestrictionStage::Notified, $state, $at)) {
            $applied[] = WalletRestrictionStage::Notified;
        }

        if (! $state->isShort()) {
            return $applied;
        }

        /*
         * The time §24.1 allotted. While it is running the account keeps
         * everything — that is what a grace period is, and acting during it
         * would make the configured period decorative.
         */
        if (! $this->evaluate->graceHasExpired($wallet, $at)) {
            return $applied;
        }

        foreach ($this->authorisedStages($wallet) as $stage) {
            if ($this->apply($wallet, $stage, $state, $at)) {
                $applied[] = $stage;
            }
        }

        return $applied;
    }

    /**
     * Which stages this account's captured obligation actually authorises.
     *
     * In §24.3's order, and read from the rule the obligation came from: an
     * account is restricted under the policy it agreed to, not under the one in
     * force this morning.
     *
     * @return array<int, WalletRestrictionStage>
     */
    public function authorisedStages(Wallet $wallet): array
    {
        $rule = $wallet->deposit_rule_id === null
            ? null
            : $wallet->depositRule()->first();

        if ($rule === null) {
            return [];
        }

        $authorised = [
            WalletRestrictionStage::ServicesRestricted->value => $rule->restricts_chargeable_services,
            WalletRestrictionStage::WebsiteSetupPaused->value => $rule->pauses_website_setup,
            WalletRestrictionStage::WebsiteDisabled->value => $rule->disables_website,
            WalletRestrictionStage::AccountRestricted->value => $rule->restricts_account,
            WalletRestrictionStage::AccountDisabled->value => $rule->disables_account,
        ];

        return array_values(array_filter(
            WalletRestrictionStage::graded(),
            fn (WalletRestrictionStage $stage) => $authorised[$stage->value] ?? false,
        ));
    }

    /**
     * Place one stage, once.
     *
     * Returns false when it was already standing, which is the ordinary case on
     * every sweep after the first.
     */
    protected function apply(
        Wallet $wallet,
        WalletRestrictionStage $stage,
        WalletBalanceState $state,
        CarbonImmutable $at,
    ): bool {
        if ($this->alreadyStanding($wallet, $stage)) {
            return false;
        }

        try {
            $restriction = $this->database->transaction(
                fn () => $this->place($wallet, $stage, $at)
            );
        } catch (UniqueConstraintViolationException) {
            // Two sweeps raced. The index settled it and the other one won,
            // which is the outcome either way.
            return false;
        }

        if ($stage === WalletRestrictionStage::Notified) {
            $this->announce($wallet, $state);
        }

        $this->record($wallet, $restriction, $stage);

        return true;
    }

    /**
     * Write the restriction and move the account's status where the stage says.
     */
    protected function place(
        Wallet $wallet,
        WalletRestrictionStage $stage,
        CarbonImmutable $at,
    ): WalletRestriction {
        /** @var BusinessAccount $account */
        $account = BusinessAccount::query()->lockForUpdate()->findOrFail($wallet->business_account_id);

        $target = $this->statusFor($stage);
        $previous = $account->status;

        $applied = null;

        if ($target !== null && $account->status !== $target) {
            /*
             * Walked, not jumped. The status machine encodes §24.3's own
             * escalation and refuses to skip a rung — an account cannot be
             * disabled without first being restricted — so a stage that wants
             * the far end takes every legal step to get there.
             */
            foreach ($this->route->between($account->status, $target) as $step) {
                $this->changeStatus->handle($account, new AccountStatusChange(
                    to: $step,
                    reason: 'Wallet balance below the required amount (§24.3).',
                    userVisibleNote: __('wallet.notice.status_note'),
                ));

                $applied = $step->value;
            }
        }

        return WalletRestriction::create([
            'wallet_id' => $wallet->id,
            'business_account_id' => $account->id,
            'stage' => $stage,
            'cause' => WalletRestriction::LOW_BALANCE,

            /*
             * Where it came from, so restoration can put it back rather than
             * guessing at "active" — an account already restricted for
             * something else must not be handed a working panel by paying a
             * deposit.
             */
            'previous_account_status' => $previous->value,
            'applied_account_status' => $applied,
            'reason' => 'Wallet balance below the required amount (§24.3).',
            'started_at' => $at,
        ]);
    }

    /**
     * The account status a stage moves to, where it moves one at all.
     *
     * The two website stages take a service away without touching the account's
     * own standing, so they move nothing: the restriction row is the record, and
     * the module that owns websites reads it.
     *
     * `ServicesRestricted` maps to `WalletTopupRequired` rather than inventing a
     * status: §24.3 lists "required top-up will be displayed" alongside
     * restricting chargeable services, and that is precisely what the status
     * says to the account holder.
     */
    protected function statusFor(WalletRestrictionStage $stage): ?AccountStatus
    {
        return match ($stage) {
            WalletRestrictionStage::Notified => AccountStatus::LowWalletBalance,
            WalletRestrictionStage::ServicesRestricted => AccountStatus::WalletTopupRequired,
            WalletRestrictionStage::WebsiteSetupPaused,
            WalletRestrictionStage::WebsiteDisabled => null,
            WalletRestrictionStage::AccountRestricted => AccountStatus::TemporarilyRestricted,
            WalletRestrictionStage::AccountDisabled => AccountStatus::TemporarilyDisabled,
        };
    }

    protected function alreadyStanding(Wallet $wallet, WalletRestrictionStage $stage): bool
    {
        return WalletRestriction::query()
            ->where('wallet_id', $wallet->id)
            ->where('stage', $stage)
            ->fromLowBalance()
            ->standing()
            ->exists();
    }

    /**
     * Tell the account holder (§24.3).
     *
     * Never allowed to undo a restriction. The balance is the fact; the message
     * is a courtesy that may fail, and an SMS provider having a bad afternoon
     * must not leave an account restricted with no record of why.
     */
    protected function announce(Wallet $wallet, WalletBalanceState $state): void
    {
        try {
            $obligation = WalletDepositObligation::query()
                ->where('wallet_id', $wallet->id)
                ->orderByDesc('id')
                ->first();

            /*
             * From the captured obligation where there is one: an account is
             * told what **it** was asked to hold, not what the policy says this
             * morning.
             */
            $required = $obligation === null
                ? $wallet->reservedObligation()
                : $obligation->required_deposit->plus($obligation->minimum_balance);

            $wallet->businessAccount()->with('owner')->first()?->owner?->notify(
                new WalletBalanceLow(
                    state: $state,
                    shortfall: $wallet->obligationShortfall(),
                    required: $required,
                    graceEndsAt: $wallet->grace_ends_at,
                ),
            );
        } catch (Throwable $throwable) {
            $this->log->channel('wallet')->error('Could not warn an account about its balance', [
                'wallet' => $wallet->public_id,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    protected function record(
        Wallet $wallet,
        WalletRestriction $restriction,
        WalletRestrictionStage $stage,
    ): void {
        $this->audit->handle(new AuditEntry(
            action: 'wallet.restriction_applied',
            auditableType: WalletRestriction::class,
            auditableId: $restriction->id,
            after: [
                'stage' => $stage->value,
                'previous_account_status' => $restriction->previous_account_status,
                'applied_account_status' => $restriction->applied_account_status,
            ],
            reason: $restriction->reason,
            accountId: $wallet->business_account_id,
            module: 'wallet',
        ));
    }
}
