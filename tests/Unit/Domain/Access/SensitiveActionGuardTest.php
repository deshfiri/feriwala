<?php

use App\Domain\Access\Data\SensitiveActionRequest;
use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Exceptions\SensitiveActionRefused;
use App\Domain\Access\SensitiveActionGuard;

function guard(): SensitiveActionGuard
{
    return new SensitiveActionGuard;
}

/**
 * A fully satisfied request for releasing a withdrawal — the most guarded
 * action in the system. Individual tests knock out one control at a time.
 */
function releasePayment(
    bool $passwordConfirmed = true,
    bool $twoFactorEnabled = true,
    bool $secondApproverGranted = true,
    ?string $reason = 'Verified against the bank statement.',
): SensitiveActionRequest {
    return new SensitiveActionRequest(
        module: PermissionModule::Withdrawal,
        action: PermissionAction::ReleasePayment,
        reason: $reason,
        passwordConfirmed: $passwordConfirmed,
        twoFactorEnabled: $twoFactorEnabled,
        secondApproverGranted: $secondApproverGranted,
    );
}

it('allows a fully satisfied sensitive action', function () {
    $approver = viewerWith(['withdrawal.release_payment']);

    expect(guard()->allows($approver, releasePayment()))->toBeTrue();
});

it('refuses when the permission is not held', function () {
    $nobody = viewerWith([]);

    expect(fn () => guard()->authorize($nobody, releasePayment()))
        ->toThrow(SensitiveActionRefused::class, 'do not have permission');
});

it('refuses a guest', function () {
    expect(fn () => guard()->authorize(null, releasePayment()))
        ->toThrow(SensitiveActionRefused::class);
});

it('refuses without a confirmed password', function () {
    $approver = viewerWith(['withdrawal.release_payment']);

    expect(fn () => guard()->authorize($approver, releasePayment(passwordConfirmed: false)))
        ->toThrow(SensitiveActionRefused::class, 'confirm your password');
});

it('refuses money movement without two-factor', function () {
    $approver = viewerWith(['withdrawal.release_payment']);

    expect(fn () => guard()->authorize($approver, releasePayment(twoFactorEnabled: false)))
        ->toThrow(SensitiveActionRefused::class, 'two-factor');
});

it('refuses a payout without a second approver', function () {
    // One person must not both authorise and execute money leaving the platform.
    $approver = viewerWith(['withdrawal.release_payment']);

    expect(fn () => guard()->authorize($approver, releasePayment(secondApproverGranted: false)))
        ->toThrow(SensitiveActionRefused::class, 'second authorised person');
});

it('refuses without a reason', function () {
    $approver = viewerWith(['withdrawal.release_payment']);

    expect(fn () => guard()->authorize($approver, releasePayment(reason: null)))
        ->toThrow(SensitiveActionRefused::class, 'requires a reason');
});

it('treats a blank reason as no reason', function () {
    $approver = viewerWith(['withdrawal.release_payment']);

    expect(fn () => guard()->authorize($approver, releasePayment(reason: '   ')))
        ->toThrow(SensitiveActionRefused::class, 'requires a reason');
});

it('reports the missing permission before anything the user could fix', function () {
    // Someone lacking both permission and confirmation should be told the thing
    // that actually blocks them, not sent to confirm a password pointlessly.
    $nobody = viewerWith([]);

    expect(fn () => guard()->authorize($nobody, releasePayment(passwordConfirmed: false)))
        ->toThrow(SensitiveActionRefused::class, 'do not have permission');
});

describe('non-sensitive actions', function () {
    it('need only the permission', function () {
        $viewer = viewerWith(['order.view']);

        $request = new SensitiveActionRequest(
            module: PermissionModule::Order,
            action: PermissionAction::View,
        );

        expect($request->isSensitive())->toBeFalse()
            ->and(guard()->allows($viewer, $request))->toBeTrue();
    });

    it('are still refused without the permission', function () {
        $request = new SensitiveActionRequest(
            module: PermissionModule::Order,
            action: PermissionAction::View,
        );

        expect(guard()->allows(viewerWith([]), $request))->toBeFalse();
    });
});

describe('which controls apply', function () {
    it('requires two-factor for every action that moves money', function (PermissionAction $action) {
        $request = new SensitiveActionRequest(
            module: PermissionModule::Wallet,
            action: $action,
        );

        expect($request->requiresTwoFactor())->toBeTrue();
    })->with([
        PermissionAction::ReleasePayment,
        PermissionAction::AdjustWallet,
        PermissionAction::ReverseTransaction,
        PermissionAction::ManageBackups,
    ]);

    it('requires a second approver only for releasing payment', function () {
        foreach (PermissionAction::cases() as $action) {
            $request = new SensitiveActionRequest(
                module: PermissionModule::Withdrawal,
                action: $action,
            );

            expect($request->requiresSecondApprover())
                ->toBe($action === PermissionAction::ReleasePayment);
        }
    });

    it('requires a reason for every sensitive action', function () {
        foreach (PermissionAction::cases() as $action) {
            $request = new SensitiveActionRequest(
                module: PermissionModule::Wallet,
                action: $action,
            );

            expect($request->requiresReason())->toBe($action->isSensitive());
        }
    });

    it('does not demand two-factor for merely viewing KYC documents', function () {
        // Sensitive enough for password confirmation and a reason, but not a
        // money movement — the controls are graded, not all-or-nothing.
        $request = new SensitiveActionRequest(
            module: PermissionModule::Kyc,
            action: PermissionAction::ViewKycDocuments,
        );

        expect($request->isSensitive())->toBeTrue()
            ->and($request->requiresTwoFactor())->toBeFalse()
            ->and($request->requiresSecondApprover())->toBeFalse();
    });
});

it('composes the permission name from module and action', function () {
    expect(releasePayment()->permission())->toBe('withdrawal.release_payment');
});
