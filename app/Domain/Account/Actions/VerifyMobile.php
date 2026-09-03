<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\VerificationCodes;
use App\Models\User;
use Illuminate\Database\DatabaseManager;

/**
 * Confirms a mobile number against a submitted code (§5.1).
 *
 * Advancing the account's status is deliberately separate from stamping the
 * verification: verification is a fact about the number, while the status move
 * depends on where the account is in the funnel. An account that verifies its
 * mobile after already reaching KYC should not be dragged backwards.
 */
class VerifyMobile
{
    public function __construct(
        protected VerificationCodes $codes,
        protected ChangeAccountStatus $changeStatus,
        protected DatabaseManager $database,
    ) {}

    /**
     * @return bool whether the code was correct
     */
    public function handle(User $user, string $submittedCode): bool
    {
        $mobile = (string) $user->mobile;

        if (! $this->codes->verify(SendMobileVerificationCode::PURPOSE, $mobile, $submittedCode)) {
            return false;
        }

        $this->database->transaction(function () use ($user) {
            $user->forceFill(['mobile_verified_at' => now()])->save();

            $this->advanceIfWaitingOnMobile($user);
        });

        return true;
    }

    /**
     * Move the account on only when mobile verification was what it was waiting
     * for, and only when the move is legal.
     */
    protected function advanceIfWaitingOnMobile(User $user): void
    {
        // Verifying a mobile is a fact about the person; advancing the funnel is
        // a change to their business (D23). Platform staff have no account, and
        // there is simply nothing to advance for them.
        $account = $user->businessAccount;

        if ($account === null) {
            return;
        }

        $next = match ($account->status) {
            AccountStatus::Registered,
            AccountStatus::MobileVerificationPending => $user->email_verified_at === null
                ? AccountStatus::EmailVerificationPending
                : AccountStatus::KycPending,

            default => null,
        };

        if ($next === null || ! $account->canTransitionTo($next)) {
            return;
        }

        $this->changeStatus->handle($account, new AccountStatusChange(
            to: $next,
            reason: 'Mobile number verified.',
        ));
    }
}
