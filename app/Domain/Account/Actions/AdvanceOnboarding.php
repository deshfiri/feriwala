<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\ActivationRequirements;
use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use Illuminate\Database\DatabaseManager;
use Illuminate\Log\LogManager;
use Throwable;

/**
 * Moves an account along the onboarding order when one of its requirements
 * lands — an activation payment settled, a KYC approved — and never activates
 * it (D39, superseding D27).
 *
 *   1. **Paid** — an account still at `PaymentPending` whose activation payment
 *      has settled records the payment as received
 *      (`PaymentVerificationPending`), and, while its KYC is not yet approved,
 *      goes on to KYC (`KycPending`): since D39 the package is chosen and paid
 *      for first.
 *   2. **Ready** — {@see EvaluateActivationReadiness} then moves an account that
 *      meets every requirement, the submitted application included, to the
 *      approval gate (`ApprovalPending`, shown as "Pending verification"), where
 *      a person approves it, asks for a correction or suspends it.
 *
 * Activation itself only ever happens through {@see ActivateAccount} with a
 * named approver.
 *
 * Safe to call as often as anything wants to: settlement is announced by the
 * gateway's IPN, the browser returning and the reconciliation sweep, and each
 * step here does nothing when the account is already past it. It never throws —
 * a failure here must never roll back a payment that genuinely arrived; the
 * account stays where it is and the next trigger moves it.
 */
class AdvanceOnboarding
{
    public function __construct(
        protected ActivationRequirements $requirements,
        protected EvaluateActivationReadiness $readiness,
        protected ChangeAccountStatus $changeStatus,
        protected DatabaseManager $database,
        protected LogManager $log,
    ) {}

    /**
     * @param  string  $trigger  what made this worth re-checking, recorded on the status history
     * @return bool whether the account is at the approval gate afterwards
     */
    public function handle(BusinessAccount $account, string $trigger): bool
    {
        if ($account->isActivated()) {
            return false;
        }

        try {
            $this->afterPayment($account);

            return $this->readiness->handle($account, $trigger);
        } catch (Throwable $throwable) {
            $this->log->channel('payment')->error('Could not advance an account through onboarding', [
                'business_account' => $account->id,
                'trigger' => $trigger,
                'error' => $throwable->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Record a settled activation payment and, when KYC is still to come, open it.
     */
    protected function afterPayment(BusinessAccount $account): void
    {
        $this->database->transaction(function () use ($account): void {
            /** @var BusinessAccount|null $locked */
            $locked = BusinessAccount::query()->whereKey($account->id)->lockForUpdate()->first();

            if ($locked === null || ! $this->requirements->activationPaid($locked)) {
                return;
            }

            if ($locked->status === AccountStatus::PaymentPending
                && $locked->canTransitionTo(AccountStatus::PaymentVerificationPending)) {
                $this->changeStatus->handle($locked, new AccountStatusChange(
                    to: AccountStatus::PaymentVerificationPending,
                    reason: 'Activation payment received.',
                ));
            }

            if ($locked->status === AccountStatus::PaymentVerificationPending
                && ! $this->requirements->kycApproved($locked)
                && $locked->canTransitionTo(AccountStatus::KycPending)) {
                $this->changeStatus->handle($locked, new AccountStatusChange(
                    to: AccountStatus::KycPending,
                    reason: 'Activation payment verified. Identity verification is next.',
                ));
            }

            // Paid, identity already approved (an account that began before
            // D39), and the application still to come: it waits as KYC-approved,
            // where its details, business setup and submission are open — never
            // as "payment verification pending" for a payment long since in.
            if ($locked->status === AccountStatus::PaymentVerificationPending
                && $this->requirements->kycApproved($locked)
                && ! $locked->hasSubmittedApplication()
                && $locked->canTransitionTo(AccountStatus::KycApproved)) {
                $this->changeStatus->handle($locked, new AccountStatusChange(
                    to: AccountStatus::KycApproved,
                    reason: 'Activation payment verified. The application is next.',
                ));
            }

            $account->setRawAttributes($locked->getAttributes(), sync: true);
        });
    }
}
