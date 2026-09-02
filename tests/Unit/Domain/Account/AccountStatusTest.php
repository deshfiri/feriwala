<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Support\StateMachine\TransitionableState;

/**
 * Walk a path of statuses, asserting each step is legal.
 *
 * @param  array<int, AccountStatus>  $path
 */
function assertPathIsLegal(array $path): void
{
    $illegal = [];

    foreach ($path as $index => $status) {
        if ($index === 0) {
            continue;
        }

        $from = $path[$index - 1];

        if (! in_array($status, $from->transitionsTo(), true)) {
            $illegal[] = "{$from->value} -> {$status->value}";
        }
    }

    expect($illegal)->toBe([]);
}

describe('the specification', function () {
    it('defines exactly the twenty-two statuses in §5.3', function () {
        expect(AccountStatus::cases())->toHaveCount(22);
    });

    it('gives every status a label and a tone', function () {
        foreach (AccountStatus::cases() as $status) {
            expect($status->label())->not->toBe('')
                ->and($status->tone())->toBeIn(['success', 'warning', 'danger', 'info', 'neutral']);
        }
    });

    it('implements the shared state machine contract', function () {
        expect(AccountStatus::Active)->toBeInstanceOf(TransitionableState::class);
    });
});

describe('the activation funnel', function () {
    it('allows the full §5.1 path from registration to active', function () {
        assertPathIsLegal([
            AccountStatus::Registered,
            AccountStatus::MobileVerificationPending,
            AccountStatus::EmailVerificationPending,
            AccountStatus::KycPending,
            AccountStatus::KycSubmitted,
            AccountStatus::KycUnderReview,
            AccountStatus::KycApproved,
            AccountStatus::PackageSelectionPending,
            AccountStatus::PaymentPending,
            AccountStatus::PaymentVerificationPending,
            AccountStatus::ApprovalPending,
            AccountStatus::Active,
        ]);
    });

    it('reaches Active only through administrative approval', function () {
        // §5.1 and §44: activation requires payment verification, KYC approval,
        // and administrative approval. Approval is the sole gateway.
        $routesIn = array_filter(
            AccountStatus::cases(),
            fn (AccountStatus $status) => in_array(
                AccountStatus::Active,
                $status->transitionsTo(),
                true,
            ),
        );

        expect($routesIn)->not->toContain(AccountStatus::KycApproved)
            ->and($routesIn)->not->toContain(AccountStatus::PaymentVerificationPending)
            ->and($routesIn)->toContain(AccountStatus::ApprovalPending);
    });

    it('never lets registration jump straight to active', function () {
        expect(AccountStatus::Registered->transitionsTo())
            ->not->toContain(AccountStatus::Active);
    });

    it('never lets an unverified KYC reach package selection', function () {
        foreach ([AccountStatus::KycPending, AccountStatus::KycSubmitted, AccountStatus::KycUnderReview] as $status) {
            expect($status->transitionsTo())
                ->not->toContain(AccountStatus::PackageSelectionPending);
        }
    });

    it('lets a user change package before paying', function () {
        expect(AccountStatus::PaymentPending->transitionsTo())
            ->toContain(AccountStatus::PackageSelectionPending);
    });

    it('sends a failed payment back to payment, not forward', function () {
        expect(AccountStatus::PaymentVerificationPending->transitionsTo())
            ->toContain(AccountStatus::PaymentPending)
            ->and(AccountStatus::PaymentVerificationPending->transitionsTo())
            ->not->toContain(AccountStatus::Active);
    });
});

describe('KYC outcomes', function () {
    it('allows approve, reject, and request corrections from review', function () {
        // The three reviewer outcomes of §7.3. Closing is also reachable, but
        // that is an account action rather than a review decision.
        expect(AccountStatus::KycUnderReview->transitionsTo())
            ->toContain(AccountStatus::KycApproved)
            ->toContain(AccountStatus::KycRejected)
            ->toContain(AccountStatus::KycResubmissionRequired);
    });

    it('allows a rejected submission to be resubmitted', function () {
        // §7.3 lists "request resubmission" as a reviewer action, so rejection
        // must not be a dead end.
        assertPathIsLegal([
            AccountStatus::KycRejected,
            AccountStatus::KycResubmissionRequired,
            AccountStatus::KycSubmitted,
        ]);
    });
});

describe('restriction and restoration', function () {
    it('restores an account to active after a top-up', function () {
        // §24.3: services are restored after sufficient top-up and verification.
        expect(AccountStatus::LowWalletBalance->transitionsTo())->toContain(AccountStatus::Active)
            ->and(AccountStatus::WalletTopupRequired->transitionsTo())->toContain(AccountStatus::Active);
    });

    it('restores an account after package renewal', function () {
        // §8.4: features are restored after successful renewal verification.
        expect(AccountStatus::PackageExpired->transitionsTo())->toContain(AccountStatus::Active);
    });

    it('lets a suspended account be reinstated', function () {
        expect(AccountStatus::Suspended->transitionsTo())->toContain(AccountStatus::Active);
    });

    it('escalates from restricted to disabled to suspended', function () {
        assertPathIsLegal([
            AccountStatus::TemporarilyRestricted,
            AccountStatus::TemporarilyDisabled,
            AccountStatus::Suspended,
        ]);
    });
});

describe('closure', function () {
    it('is terminal', function () {
        expect(AccountStatus::Closed->isTerminal())->toBeTrue()
            ->and(AccountStatus::Closed->transitionsTo())->toBe([]);
    });

    it('is the only terminal status', function () {
        $terminal = array_filter(
            AccountStatus::cases(),
            fn (AccountStatus $status) => $status->isTerminal(),
        );

        expect($terminal)->toBe([21 => AccountStatus::Closed]);
    });

    it('is reachable from every non-terminal status', function () {
        // D18 preserves a closed account read-only, so closing must always be
        // possible — an account with no way out is a support problem.
        $cannotClose = [];

        foreach (AccountStatus::cases() as $status) {
            if ($status->isTerminal()) {
                continue;
            }

            if (! in_array(AccountStatus::Closed, $status->transitionsTo(), true)) {
                $cannotClose[] = $status->value;
            }
        }

        expect($cannotClose)->toBe([]);
    });
});

describe('capability helpers', function () {
    it('treats only post-approval statuses as activated', function () {
        expect(AccountStatus::Active->isActivated())->toBeTrue()
            ->and(AccountStatus::LowWalletBalance->isActivated())->toBeTrue()
            ->and(AccountStatus::ApprovalPending->isActivated())->toBeFalse()
            ->and(AccountStatus::KycApproved->isActivated())->toBeFalse();
    });

    it('treats the whole funnel as onboarding', function () {
        expect(AccountStatus::Registered->isOnboarding())->toBeTrue()
            ->and(AccountStatus::ApprovalPending->isOnboarding())->toBeTrue()
            ->and(AccountStatus::Active->isOnboarding())->toBeFalse()
            ->and(AccountStatus::Suspended->isOnboarding())->toBeFalse();
    });

    it('stops a disabled or expired account trading while keeping its data', function () {
        // Narrower than "activated" on purpose: an account below its minimum
        // balance keeps its dashboard but stops being able to trade (§24.3).
        expect(AccountStatus::Active->canTransact())->toBeTrue()
            ->and(AccountStatus::LowWalletBalance->canTransact())->toBeTrue()
            ->and(AccountStatus::WalletTopupRequired->canTransact())->toBeFalse()
            ->and(AccountStatus::TemporarilyRestricted->canTransact())->toBeFalse()
            ->and(AccountStatus::PackageExpired->canTransact())->toBeFalse()
            ->and(AccountStatus::Suspended->canTransact())->toBeFalse();
    });

    it('never lets a closed or suspended account transact', function () {
        foreach ([AccountStatus::Closed, AccountStatus::Suspended, AccountStatus::TemporarilyDisabled] as $status) {
            expect($status->canTransact())->toBeFalse()
                ->and($status->isActivated())->toBeFalse();
        }
    });
});
