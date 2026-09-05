<?php

use App\Domain\Account\Actions\ChangeAccountStatus;
use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\Models\BusinessAccountStatusChange;
use App\Models\User;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;

function changeStatus(): ChangeAccountStatus
{
    return app(ChangeAccountStatus::class);
}

/**
 * A business account sitting at `$status`.
 *
 * The commercial lifecycle belongs to the account, not the person (D23), so
 * these tests build accounts and reach for the owner only when they need a
 * human.
 */
function accountAt(AccountStatus $status): BusinessAccount
{
    return BusinessAccount::factory()->onboarding($status)->create();
}

it('moves the account and records the change', function () {
    $account = accountAt(AccountStatus::KycPending);

    $record = changeStatus()->handle(
        $account,
        new AccountStatusChange(
            to: AccountStatus::KycSubmitted,
            reason: 'Documents uploaded.',
        ),
    );

    expect($account->fresh()->status)->toBe(AccountStatus::KycSubmitted)
        ->and($record->from_status)->toBe(AccountStatus::KycPending)
        ->and($record->to_status)->toBe(AccountStatus::KycSubmitted)
        ->and($record->reason)->toBe('Documents uploaded.');
});

it('refuses a transition the state machine does not allow', function () {
    $account = accountAt(AccountStatus::Registered);

    expect(fn () => changeStatus()->handle(
        $account,
        new AccountStatusChange(to: AccountStatus::Active),
    ))->toThrow(IllegalStateTransition::class);
});

it('writes no history when the transition is refused', function () {
    $account = accountAt(AccountStatus::Registered);

    try {
        changeStatus()->handle($account, new AccountStatusChange(to: AccountStatus::Active));
    } catch (IllegalStateTransition) {
        // expected
    }

    expect(BusinessAccountStatusChange::where('business_account_id', $account->id)->count())->toBe(0)
        ->and($account->fresh()->status)->toBe(AccountStatus::Registered);
});

it('stamps activated_at the first time the account becomes active', function () {
    $account = accountAt(AccountStatus::ApprovalPending);

    expect($account->activated_at)->toBeNull();

    changeStatus()->handle($account, new AccountStatusChange(to: AccountStatus::Active));

    expect($account->fresh()->activated_at)->not->toBeNull();
});

it('does not move activated_at when the account is restored later', function () {
    $account = accountAt(AccountStatus::ApprovalPending);

    changeStatus()->handle($account, new AccountStatusChange(to: AccountStatus::Active));
    $firstActivation = $account->fresh()->activated_at;

    // Suspended and reinstated — the original activation date is when the
    // relationship began, and reports depend on it not moving.
    changeStatus()->handle($account, new AccountStatusChange(to: AccountStatus::Suspended));
    changeStatus()->handle($account, new AccountStatusChange(to: AccountStatus::Active));

    expect($account->fresh()->activated_at->timestamp)->toBe($firstActivation->timestamp);
});

it('keeps the internal note away from the user-visible note', function () {
    $account = accountAt(AccountStatus::KycUnderReview);
    $reviewer = User::factory()->staff()->create();

    $record = changeStatus()->handle($account, new AccountStatusChange(
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
    $account = accountAt(AccountStatus::Active);

    $record = changeStatus()->handle(
        $account,
        AccountStatusChange::automatic(
            AccountStatus::LowWalletBalance,
            'Balance fell below the package minimum.',
        ),
    );

    expect($record->changed_by)->toBeNull()
        ->and($record->wasAutomatic())->toBeTrue();
});

it('builds a full history across the activation funnel', function () {
    $account = accountAt(AccountStatus::Registered);

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
        changeStatus()->handle($account, new AccountStatusChange(to: $status));
    }

    expect($account->fresh()->status)->toBe(AccountStatus::Active)
        ->and($account->statusHistory()->count())->toBe(count($path));
});

it('refuses to edit or delete a history entry', function () {
    $account = accountAt(AccountStatus::KycPending);

    $record = changeStatus()->handle(
        $account,
        new AccountStatusChange(to: AccountStatus::KycSubmitted),
    );

    expect(fn () => $record->update(['reason' => 'rewritten']))
        ->toThrow(RuntimeException::class, 'append-only')
        ->and(fn () => $record->delete())
        ->toThrow(RuntimeException::class, 'append-only');
});

it('gives every account a public id and never exposes the primary key', function () {
    $account = accountAt(AccountStatus::Registered);

    expect($account->public_id)->not->toBeNull()
        ->and($account->getRouteKeyName())->toBe('public_id');
});

it('keeps the account status off the identity', function () {
    // D23: suspending a business must not suspend the person's login. An
    // invited staff member's own account survives their employer's suspension,
    // and so does a platform reviewer who happens to own a business.
    $account = accountAt(AccountStatus::Active);
    $owner = $account->owner;

    changeStatus()->handle($account, new AccountStatusChange(to: AccountStatus::Suspended));

    expect($account->fresh()->status)->toBe(AccountStatus::Suspended)
        ->and($owner->fresh()->hasPlatformAccess())->toBeTrue();
});
