<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\MobileVerificationRequirement;
use App\Domain\Account\Models\BusinessAccount;
use Illuminate\Database\DatabaseManager;

/**
 * Advances an account past mobile verification when an administrator has
 * switched the requirement off (§5.1).
 *
 * Mirrors {@see VerifyMobile::handle()}'s own advance step exactly -- same
 * match, same lock-then-check, same destination. The only difference is
 * what triggers it: a confirmed code there, a disabled requirement here.
 *
 * Nothing calls this on a schedule. It runs lazily, from the onboarding
 * status page and the mobile-verification screen -- the two places an
 * account sitting at {@see AccountStatus::Registered} or
 * {@see AccountStatus::MobileVerificationPending} is always looked at --
 * so an account left waiting only on mobile is caught the next time anyone
 * looks, rather than needing a job to sweep for it. A no-op, cheaply, for
 * every account not in that exact position.
 */
class SkipMobileVerificationIfNotRequired
{
    public function __construct(
        protected MobileVerificationRequirement $requirement,
        protected ChangeAccountStatus $changeStatus,
        protected DatabaseManager $database,
    ) {}

    public function handle(BusinessAccount $account): void
    {
        if ($this->requirement->isRequired()) {
            return;
        }

        if (! in_array($account->status, [AccountStatus::Registered, AccountStatus::MobileVerificationPending], true)) {
            return;
        }

        $this->database->transaction(function () use ($account) {
            /** @var BusinessAccount|null $locked */
            $locked = BusinessAccount::query()->whereKey($account->id)->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            $owner = $locked->owner;

            $next = match ($locked->status) {
                AccountStatus::Registered,
                AccountStatus::MobileVerificationPending => $owner === null || $owner->email_verified_at === null
                    ? AccountStatus::EmailVerificationPending
                    : AccountStatus::KycPending,

                default => null,
            };

            if ($next === null || ! $locked->canTransitionTo($next)) {
                return;
            }

            $this->changeStatus->handle($locked, new AccountStatusChange(
                to: $next,
                reason: 'Mobile verification is switched off.',
            ));
        });
    }
}
