<?php

namespace App\Domain\Account;

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\OnboardingStep;
use App\Domain\Kyc\Models\KycSubmission;
use App\Models\User;

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
    public function for(User $user): array
    {
        $status = $user->status;
        $current = OnboardingStep::forStatus($status);

        return [
            'is_complete' => $user->isActivated(),
            'current_step' => $current->value,
            'steps' => $this->steps($user, $current),
            'action' => $this->requiredAction($user),
            'blocked_reason' => $this->blockedReason($user),
            'feedback' => $this->latestFeedback($user),
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
    protected function steps(User $user, OnboardingStep $current): array
    {
        $steps = [];

        foreach (OnboardingStep::ordered() as $step) {
            $steps[] = [
                'key' => $step->value,
                'label' => $step->label(),
                'position' => $step->position(),
                'state' => $this->stateOf($user, $step, $current),
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
    protected function stateOf(User $user, OnboardingStep $step, OnboardingStep $current): string
    {
        if ($user->isActivated()) {
            return 'done';
        }

        if ($step->position() < $current->position()) {
            return 'done';
        }

        if ($step !== $current) {
            return 'upcoming';
        }

        return $this->isBlocked($user) ? 'blocked' : 'current';
    }

    protected function isBlocked(User $user): bool
    {
        return in_array($user->status, [
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
    protected function requiredAction(User $user): ?array
    {
        return match ($user->status) {
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
    protected function blockedReason(User $user): ?string
    {
        return match ($user->status) {
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
    protected function latestFeedback(User $user): ?string
    {
        if (! $this->isBlocked($user)) {
            return null;
        }

        $submission = KycSubmission::query()
            ->where('user_id', $user->id)
            ->orderByDesc('round')
            ->first();

        return $submission?->reviews()->first()?->user_visible_feedback;
    }
}
