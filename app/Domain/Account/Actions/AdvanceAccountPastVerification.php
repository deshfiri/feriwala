<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\MobileVerificationRequirement;
use App\Domain\Account\Models\BusinessAccount;
use Illuminate\Database\DatabaseManager;

/**
 * Moves an account on from the verification steps to KYC once everything
 * verification currently asks of its owner is done: their email, and their
 * mobile while the administrator's requirement is on.
 *
 * The same destination {@see VerifyMobile} and
 * {@see SkipMobileVerificationIfNotRequired} use, reached by the same status
 * machine -- so confirming a channel by hand cannot strand an account on a
 * step it has already satisfied. A no-op for any account not sitting at one of
 * the three verification statuses.
 */
class AdvanceAccountPastVerification
{
    public function __construct(
        protected MobileVerificationRequirement $requirement,
        protected ChangeAccountStatus $changeStatus,
        protected DatabaseManager $database,
    ) {}

    public function handle(BusinessAccount $account, string $reason): void
    {
        if (! in_array($account->status, [
            AccountStatus::Registered,
            AccountStatus::MobileVerificationPending,
            AccountStatus::EmailVerificationPending,
        ], true)) {
            return;
        }

        $this->database->transaction(function () use ($account, $reason) {
            /** @var BusinessAccount|null $locked */
            $locked = BusinessAccount::query()->whereKey($account->id)->lockForUpdate()->first();
            $owner = $locked?->owner;

            if ($locked === null || $owner === null || $owner->email_verified_at === null) {
                return;
            }

            if ($this->requirement->isRequired() && $owner->mobile_verified_at === null) {
                return;
            }

            if (! $locked->canTransitionTo(AccountStatus::KycPending)) {
                return;
            }

            $this->changeStatus->handle($locked, new AccountStatusChange(to: AccountStatus::KycPending, reason: $reason));
        });
    }
}
