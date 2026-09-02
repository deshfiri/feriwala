<?php

use App\Domain\Account\Actions\ChangeAccountStatus;
use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\UserStatusChange;
use App\Models\User;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;

function changeStatus(): ChangeAccountStatus
{
    return app(ChangeAccountStatus::class);
}

it('moves the account and records the change', function () {
    $user = User::factory()->create(['status' => AccountStatus::KycPending]);

    $record = changeStatus()->handle(
        $user,
        new AccountStatusChange(
            to: AccountStatus::KycSubmitted,
            reason: 'Documents uploaded.',
        ),
    );

    expect($user->fresh()->status)->toBe(AccountStatus::KycSubmitted)
        ->and($record->from_status)->toBe(AccountStatus::KycPending)
        ->and($record->to_status)->toBe(AccountStatus::KycSubmitted)
        ->and($record->reason)->toBe('Documents uploaded.');
});

it('refuses a transition the state machine does not allow', function () {
    $user = User::factory()->create(['status' => AccountStatus::Registered]);

    expect(fn () => changeStatus()->handle(
        $user,
        new AccountStatusChange(to: AccountStatus::Active),
    ))->toThrow(IllegalStateTransition::class);
});

it('writes no history when the transition is refused', function () {
    $user = User::factory()->create(['status' => AccountStatus::Registered]);

    try {
        changeStatus()->handle($user, new AccountStatusChange(to: AccountStatus::Active));
    } catch (IllegalStateTransition) {
        // expected
    }

    expect(UserStatusChange::where('user_id', $user->id)->count())->toBe(0)
        ->and($user->fresh()->status)->toBe(AccountStatus::Registered);
});

it('stamps activated_at the first time the account becomes active', function () {
    $user = User::factory()->create(['status' => AccountStatus::ApprovalPending]);

    expect($user->activated_at)->toBeNull();

    changeStatus()->handle($user, new AccountStatusChange(to: AccountStatus::Active));

    expect($user->fresh()->activated_at)->not->toBeNull();
});

it('does not move activated_at when the account is restored later', function () {
    $user = User::factory()->create(['status' => AccountStatus::ApprovalPending]);

    changeStatus()->handle($user, new AccountStatusChange(to: AccountStatus::Active));
    $firstActivation = $user->fresh()->activated_at;

    // Suspended and reinstated — the original activation date is when the
    // relationship began, and reports depend on it not moving.
    changeStatus()->handle($user, new AccountStatusChange(to: AccountStatus::Suspended));
    changeStatus()->handle($user, new AccountStatusChange(to: AccountStatus::Active));

    expect($user->fresh()->activated_at->timestamp)->toBe($firstActivation->timestamp);
});

it('keeps the internal note away from the user-visible note', function () {
    $user = User::factory()->create(['status' => AccountStatus::KycUnderReview]);
    $reviewer = User::factory()->create();

    $record = changeStatus()->handle($user, new AccountStatusChange(
        to: AccountStatus::KycResubmissionRequired,
        changedBy: $reviewer->id,
        reason: 'Address proof unreadable.',
        internalNote: 'Third attempt — escalate if it happens again.',
        userVisibleNote: 'Please upload a clearer photo of your utility bill.',
    ));

    expect($record->internal_note)->toBe('Third attempt — escalate if it happens again.')
        ->and($record->user_visible_note)->toBe('Please upload a clearer photo of your utility bill.')
        ->and($record->changedBy->id)->toBe($reviewer->id);
});

it('records a system change with no actor', function () {
    $user = User::factory()->create(['status' => AccountStatus::Active]);

    $record = changeStatus()->handle(
        $user,
        AccountStatusChange::automatic(
            AccountStatus::LowWalletBalance,
            'Balance fell below the package minimum.',
        ),
    );

    expect($record->changed_by)->toBeNull()
        ->and($record->wasAutomatic())->toBeTrue();
});

it('builds a full history across the activation funnel', function () {
    $user = User::factory()->create(['status' => AccountStatus::Registered]);

    $path = [
        AccountStatus::KycPending,
        AccountStatus::KycSubmitted,
        AccountStatus::KycUnderReview,
        AccountStatus::KycApproved,
        AccountStatus::PackageSelectionPending,
        AccountStatus::PaymentPending,
        AccountStatus::PaymentVerificationPending,
        AccountStatus::ApprovalPending,
        AccountStatus::Active,
    ];

    foreach ($path as $status) {
        changeStatus()->handle($user, new AccountStatusChange(to: $status));
    }

    expect($user->fresh()->status)->toBe(AccountStatus::Active)
        ->and($user->statusHistory()->count())->toBe(count($path));
});

it('refuses to edit or delete a history entry', function () {
    $user = User::factory()->create(['status' => AccountStatus::KycPending]);

    $record = changeStatus()->handle(
        $user,
        new AccountStatusChange(to: AccountStatus::KycSubmitted),
    );

    expect(fn () => $record->update(['reason' => 'rewritten']))
        ->toThrow(RuntimeException::class, 'append-only')
        ->and(fn () => $record->delete())
        ->toThrow(RuntimeException::class, 'append-only');
});

it('gives every account a public id and never exposes the primary key', function () {
    $user = User::factory()->create();

    expect($user->public_id)->not->toBeNull()
        ->and($user->getRouteKeyName())->toBe('public_id');
});
