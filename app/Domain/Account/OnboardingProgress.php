<?php

namespace App\Domain\Account;

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\OnboardingStep;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Kyc\Models\KycSubmission;

/**
 * Works out where an account is in the activation funnel (§33.4).
 *
 * §33.4 requires the user to always understand: the current step, which are
 * done, which is pending, what they must do, why they were rejected, what is due
 * to pay, and what happens next. This assembles all of that in one place so the
 * stepper, the dashboard, and any notification say the same thing — a user told
 * two different things about their own application loses trust in both.
 */
class OnboardingProgress
{
    public function __construct(
        protected ActivationRequirements $requirements,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(BusinessAccount $account): array
    {
        $status = $account->status;
        $current = OnboardingStep::forStatus($status);

        return [
            'is_complete' => $account->isActivated(),
            'current_step' => $current->value,
            'steps' => $this->steps($account, $current),
            'action' => $this->requiredAction($account),
            'blocked_reason' => $this->blockedReason($account),
            'feedback' => $this->latestFeedback($account),
            'status' => [
                'value' => $status->value,
                'label' => $status->label(),
                'tone' => $status->tone(),
            ],
        ];
    }

    /**
     * Each step with its state.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function steps(BusinessAccount $account, OnboardingStep $current): array
    {
        $steps = [];

        foreach (OnboardingStep::ordered() as $step) {
            $steps[] = [
                'key' => $step->value,
                'label' => $step->label(),
                'position' => $step->position(),
                'state' => $this->stateOf($account, $step, $current),
            ];
        }

        return $steps;
    }

    /**
     * done | current | blocked | upcoming
     *
     * A blocked step is shown in place rather than as an error banner, so the
     * user can see it is their KYC that needs attention and not wonder which
     * part of the process went wrong.
     */
    protected function stateOf(BusinessAccount $account, OnboardingStep $step, OnboardingStep $current): string
    {
        if ($account->isActivated()) {
            return 'done';
        }

        if ($step->position() < $current->position()) {
            return 'done';
        }

        if ($step !== $current) {
            return 'upcoming';
        }

        return $this->isBlocked($account) ? 'blocked' : 'current';
    }

    protected function isBlocked(BusinessAccount $account): bool
    {
        return in_array($account->status, [
            AccountStatus::KycRejected,
            AccountStatus::KycResubmissionRequired,
        ], true);
    }

    /**
     * The single thing the user should do next.
     *
     * One action, never a list. A person part-way through signing up needs to
     * know the next thing to click, not everything still outstanding.
     *
     * @return array<string, string>|null
     */
    protected function requiredAction(BusinessAccount $account): ?array
    {
        return match ($account->status) {
            AccountStatus::Registered,
            AccountStatus::MobileVerificationPending => [
                'label' => 'Verify your mobile number',
                'route' => 'verification.mobile',
            ],

            AccountStatus::EmailVerificationPending => [
                'label' => 'Verify your email address',
                'route' => 'verification.notice',
            ],

            AccountStatus::KycPending => [
                'label' => 'Submit your KYC documents',
                'route' => 'kyc.create',
            ],

            AccountStatus::KycResubmissionRequired,
            AccountStatus::KycRejected => [
                'label' => 'Update your KYC documents',
                'route' => 'kyc.create',
            ],

            AccountStatus::KycApproved,
            AccountStatus::PackageSelectionPending => [
                'label' => 'Choose your package',
                'route' => 'packages.index',
            ],

            AccountStatus::PaymentPending => [
                'label' => 'Complete your payment',
                'route' => 'payment.checkout',
            ],

            // Nothing to do but wait — and saying so is better than showing a
            // button that does nothing.
            default => null,
        };
    }

    /**
     * Why the account cannot proceed, in the user's own terms.
     */
    protected function blockedReason(BusinessAccount $account): ?string
    {
        return match ($account->status) {
            AccountStatus::KycRejected => 'Your KYC could not be verified.',
            AccountStatus::KycResubmissionRequired => 'Your KYC needs a correction.',
            AccountStatus::Suspended => 'This account is suspended. Contact support.',
            AccountStatus::Closed => 'This account is closed.',
            default => null,
        };
    }

    /**
     * The reviewer's message to the applicant.
     *
     * Only ever the user-visible field. The internal note lives beside it in the
     * database and must never surface here (§7.3).
     */
    protected function latestFeedback(BusinessAccount $account): ?string
    {
        if (! $this->isBlocked($account)) {
            return null;
        }

        $submission = KycSubmission::query()
            ->where('business_account_id', $account->id)
            ->orderByDesc('round')
            ->first();

        return $submission?->reviews()->first()?->user_visible_feedback;
    }
}
