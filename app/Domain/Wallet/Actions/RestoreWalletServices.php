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
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletRestriction;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Give back what the balance took, and nothing else (§24.3, §43).
 *
 * "Services should be restored after sufficient top-up and Payment
 * verification." The verification has already happened — money only reaches a
 * wallet through a settled payment and a posted ledger entry — so what is left
 * is the restoring, and the whole difficulty is in the word *only*.
 *
 * Two rules make it safe:
 *
 *   - Only restrictions **this** rule placed are lifted. A restriction from a
 *     failed KYC review, a suspension, or an administrator's decision is not
 *     this module's to undo, and an account that paid its deposit must not get a
 *     working panel out of it.
 *   - The account goes back **where it was**, not to "active". Each restriction
 *     recorded the status it found; putting that back is the difference between
 *     restoring an account and promoting one.
 *
 * Idempotent. A sweep that runs every morning finds nothing standing and does
 * nothing, and two sweeps at once cannot lift the same restriction twice.
 */
class RestoreWalletServices
{
    public function __construct(
        protected EvaluateWalletBalance $evaluate,
        protected ChangeAccountStatus $changeStatus,
        protected AccountStatusRoute $route,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * Lift what can be lifted, if the money is back.
     *
     * @return array<int, WalletRestriction> what this run actually lifted
     */
    public function handle(Wallet $wallet, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();

        if ($this->evaluate->handle($wallet, $at) !== WalletBalanceState::Healthy) {
            // Still short, or still below the threshold that warned them. A
            // partial top-up buys nothing back: §24.3 restores on *sufficient*
            // top-up, and half of it is not sufficient.
            return [];
        }

        return $this->database->transaction(function () use ($wallet, $at) {
            $standing = WalletRestriction::query()
                ->where('wallet_id', $wallet->id)
                ->fromLowBalance()
                ->standing()
                ->lockForUpdate()
                // Severest first, so the account's status walks back out the
                // way it walked in.
                ->orderByDesc('id')
                ->get();

            if ($standing->isEmpty()) {
                return [];
            }

            $this->restoreStatus($wallet, $standing->all());

            foreach ($standing as $restriction) {
                $restriction->forceFill([
                    'lifted_at' => $at,
                    'lifted_reason' => 'Balance restored to the required amount (§24.3).',
                ])->save();

                $this->record($wallet, $restriction);
            }

            return $standing->all();
        });
    }

    /**
     * Put the account back where the first of these restrictions found it.
     *
     * The **earliest** previous status, not the latest: the stages were applied
     * in order, each recording what it found, so the one that moved the account
     * first is the one that knows where it started.
     *
     * Nothing is changed unless this module was what moved it. An account whose
     * status was altered by something else in the meantime is left alone — its
     * restrictions are still lifted, because those were ours, but its standing
     * is not ours to decide.
     *
     * @param  array<int, WalletRestriction>  $standing
     */
    protected function restoreStatus(Wallet $wallet, array $standing): void
    {
        $moves = array_values(array_filter(
            $standing,
            fn (WalletRestriction $restriction) => $restriction->applied_account_status !== null,
        ));

        if ($moves === []) {
            return;
        }

        // Applied in order, so the first one holds the status the account had
        // before any of this began.
        usort($moves, fn (WalletRestriction $a, WalletRestriction $b) => $a->id <=> $b->id);

        $target = AccountStatus::tryFrom((string) $moves[0]->previous_account_status);
        $lastApplied = AccountStatus::tryFrom((string) end($moves)->applied_account_status);

        if ($target === null) {
            return;
        }

        /** @var BusinessAccount $account */
        $account = BusinessAccount::query()->lockForUpdate()->findOrFail($wallet->business_account_id);

        /*
         * Only if the account is still where we left it. Somebody suspended in
         * the meantime stays suspended: restoring a balance is not a reason to
         * undo a decision this module knows nothing about.
         */
        if ($lastApplied !== null && $account->status !== $lastApplied) {
            return;
        }

        if ($account->status === $target) {
            return;
        }

        // Walked back out the way it walked in, because the status machine will
        // not skip a rung in either direction.
        foreach ($this->route->between($account->status, $target) as $step) {
            $this->changeStatus->handle($account, new AccountStatusChange(
                to: $step,
                reason: 'Wallet balance restored to the required amount (§24.3).',
                userVisibleNote: __('wallet.notice.restored_note'),
            ));
        }
    }

    protected function record(Wallet $wallet, WalletRestriction $restriction): void
    {
        $this->audit->handle(new AuditEntry(
            action: 'wallet.restriction_lifted',
            auditableType: WalletRestriction::class,
            auditableId: $restriction->id,
            before: ['stage' => $restriction->stage->value, 'lifted_at' => null],
            after: [
                'stage' => $restriction->stage->value,
                'lifted_at' => $restriction->lifted_at?->toIso8601String(),
                'restored_account_status' => $restriction->previous_account_status,
            ],
            reason: $restriction->lifted_reason,
            accountId: $wallet->business_account_id,
            module: 'wallet',
        ));
    }
}
