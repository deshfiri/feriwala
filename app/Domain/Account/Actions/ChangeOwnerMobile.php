<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Exceptions\OnboardingRefused;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\OnboardingApplication;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * The owner changes their mobile number during onboarding, and verifies the new
 * one (D39 step 5).
 *
 * The new number replaces the old and is unverified until its code is entered:
 * the account cannot reach the approval gate meanwhile, because a verified
 * mobile is an activation requirement while mobile verification is on. A code
 * is sent at once; the existing verification screen takes it from there.
 */
class ChangeOwnerMobile
{
    public function __construct(
        protected OnboardingApplication $application,
        protected SendMobileVerificationCode $sendCode,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  string  $mobile  E.164
     *
     * @throws OnboardingRefused
     */
    public function handle(BusinessAccount $account, User $owner, string $mobile): bool
    {
        $changed = $this->database->transaction(function () use ($account, $owner, $mobile): bool {
            /** @var BusinessAccount $locked */
            $locked = BusinessAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $this->application->assertEditable($locked);

            if ($owner->mobile === $mobile) {
                return false;
            }

            if (User::query()->where('mobile', $mobile)->whereKeyNot($owner->id)->exists()) {
                throw OnboardingRefused::because('mobile_taken', 'mobile');
            }

            $owner->forceFill(['mobile' => $mobile, 'mobile_verified_at' => null])->save();

            $this->audit->handle(new AuditEntry(
                action: 'onboarding.mobile_changed',
                actorId: $owner->id,
                auditableType: User::class,
                auditableId: $owner->id,
                // That it changed, never the numbers themselves.
                after: ['mobile_verified' => false],
                accountId: $locked->id,
                module: 'account',
            ));

            return true;
        });

        if ($changed) {
            try {
                $this->sendCode->handle($owner->refresh());
            } catch (Throwable) {
                // The verification screen offers to send another; a provider
                // having a bad moment must not undo the change.
            }
        }

        return $changed;
    }
}
