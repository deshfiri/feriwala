<?php

namespace App\Domain\Account;

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\OnboardingStep;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Kyc\Models\KycSubmission;

/**
 * Works out where an account is in onboarding (§33.4, D39).
 *
 * §33.4 requires the user to always understand: the current step, which are
 * done, which is pending, what they must do, why they were stopped, what is due
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
        $current = OnboardingStep::forAccount($account, $this->requirements);

        return [
            'is_complete' => $account->isActivated(),
            'current_step' => $current->value,
            'steps' => $this->steps($account, $current),
            'action' => $this->requiredAction($account, $current),
            'blocked_reason' => $this->blockedReason($account),
            'feedback' => $this->latestFeedback($account),
            // Submitted and waiting on Banij (D39): what the partner is told, and
            // where their invoice is while they wait.
            'pending' => $this->pending($account, $current),
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
        ], true) || $this->awaitsCorrection($account);
    }

    /** A reviewer asked for part of the application to be corrected and it is not resubmitted yet. */
    protected function awaitsCorrection(BusinessAccount $account): bool
    {
        return ! $account->hasSubmittedApplication() && ($account->correction_sections ?? []) !== [];
    }

    /**
     * The single thing the user should do next.
     *
     * One action, never a list. A person part-way through signing up needs to
     * know the next thing to click, not everything still outstanding.
     *
     * Resolved to an address here rather than handed over as a route name: the
     * browser cannot resolve a name, and a name used as a link is a relative
     * path that 404s — which is what every button on the stepper once did.
     *
     * @return array{label: string, url: string}|null
     */
    protected function requiredAction(BusinessAccount $account, OnboardingStep $current): ?array
    {
        [$label, $route] = match ($current) {
            OnboardingStep::Registration, OnboardingStep::Verification => match ($account->status) {
                AccountStatus::EmailVerificationPending => ['verify_email', 'verification.notice'],
                default => ['verify_mobile', 'verification.mobile'],
            },

            OnboardingStep::Package => ['choose_package', 'packages.index'],

            OnboardingStep::Payment => $account->status === AccountStatus::PaymentPending
                ? ['pay', 'checkout.show']
                : [null, null],

            // Nothing to do while the documents are with the reviewers.
            OnboardingStep::Kyc => match ($account->status) {
                AccountStatus::KycPending => ['submit_kyc', 'kyc.create'],
                AccountStatus::KycResubmissionRequired, AccountStatus::KycRejected => ['update_kyc', 'kyc.create'],
                default => [null, null],
            },

            OnboardingStep::Details => [$this->awaitsCorrection($account) ? 'correct' : 'details', 'onboarding.details.edit'],
            OnboardingStep::Business => [$this->awaitsCorrection($account) ? 'correct' : 'business', 'onboarding.business.edit'],
            OnboardingStep::Review => ['review', 'onboarding.review.show'],

            // Nothing to do but wait — and saying so is better than showing a
            // button that does nothing.
            OnboardingStep::Approval => [null, null],
        };

        if ($label === null || $route === null) {
            return null;
        }

        return [
            'label' => __("onboarding.actions.{$label}"),
            'url' => route($route, absolute: false),
        ];
    }

    /**
     * Why the account cannot proceed, in the user's own terms.
     */
    protected function blockedReason(BusinessAccount $account): ?string
    {
        return match (true) {
            $account->status === AccountStatus::KycRejected => $this->line('onboarding.blocked.kyc_rejected'),
            $account->status === AccountStatus::KycResubmissionRequired => $this->line('onboarding.blocked.kyc_correction'),
            $account->status === AccountStatus::Suspended => $this->line('onboarding.blocked.suspended'),
            $account->status === AccountStatus::Closed => $this->line('onboarding.blocked.closed'),
            $this->awaitsCorrection($account) => $this->line('onboarding.blocked.correction'),
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
        if ($this->awaitsCorrection($account)) {
            return $account->correction_feedback;
        }

        if (! in_array($account->status, [AccountStatus::KycRejected, AccountStatus::KycResubmissionRequired], true)) {
            return null;
        }

        $submission = KycSubmission::query()
            ->where('business_account_id', $account->id)
            ->orderByDesc('round')
            ->first();

        return $submission?->reviews()->first()?->user_visible_feedback;
    }

    /**
     * Once the application is submitted and the account is not yet active: the
     * "submitted successfully" message with its 24–48 hour estimate, a note when
     * the identity documents are still being reviewed, and the invoice.
     *
     * @return array{heading: string, body: string, note: string|null, invoice_url: string}|null
     */
    protected function pending(BusinessAccount $account, OnboardingStep $current): ?array
    {
        if ($current !== OnboardingStep::Approval || $account->isActivated() || ! $account->hasSubmittedApplication()) {
            return null;
        }

        return [
            'heading' => $this->line('onboarding.pending.heading'),
            'body' => $this->line('onboarding.pending.body'),
            'note' => $this->requirements->kycApproved($account) ? null : $this->line('onboarding.pending.kyc_in_review'),
            'invoice_url' => route('subscription.invoices.index', absolute: false),
        ];
    }

    protected function line(string $key): string
    {
        $line = __($key);

        return is_string($line) ? $line : $key;
    }
}
