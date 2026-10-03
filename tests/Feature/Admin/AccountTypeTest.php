<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountType;
use App\Domain\Audit\Models\AuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * Whether a trading business funds a wholesale order's product cost upfront
 * (Conditional) or only after delivery (Non-Conditional): a plain,
 * permission-gated, audited field — not a state machine.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = testPlatformStaff(PlatformRole::SuperAdmin);
});

it('defaults every account to Conditional', function () {
    $account = testBusinessAccount();

    expect($account->account_type)->toBe(AccountType::Conditional)
        ->and($account->isNonConditional())->toBeFalse();
});

it('lets staff change an account to Non-Conditional, persists it and audits it', function () {
    $account = testBusinessAccount();

    $this->actingAs($this->staff)->post(route('admin.accounts.account-type.update', $account), [
        'account_type' => AccountType::NonConditional->value,
        'reason' => 'Reseller requested delivery-only upfront charging.',
    ])->assertSessionHasNoErrors();

    expect($account->fresh()->account_type)->toBe(AccountType::NonConditional)
        ->and($account->fresh()->isNonConditional())->toBeTrue();

    $audit = AuditLog::query()->where('action', 'account.account_type_changed')->sole();

    expect($audit->actor_id)->toBe($this->staff->id)
        ->and($audit->before['account_type'])->toBe('conditional')
        ->and($audit->after['account_type'])->toBe('non_conditional')
        ->and($audit->reason)->toContain('delivery-only upfront');
});

it('requires a reason', function () {
    $account = testBusinessAccount();

    $this->actingAs($this->staff)->post(route('admin.accounts.account-type.update', $account), [
        'account_type' => AccountType::NonConditional->value,
        'reason' => '',
    ])->assertSessionHasErrors('reason');

    expect($account->fresh()->account_type)->toBe(AccountType::Conditional);
});

it('rejects an unknown account type', function () {
    $account = testBusinessAccount();

    $this->actingAs($this->staff)->post(route('admin.accounts.account-type.update', $account), [
        'account_type' => 'postpaid',
        'reason' => 'x',
    ])->assertSessionHasErrors('account_type');

    expect($account->fresh()->account_type)->toBe(AccountType::Conditional);
});

it('is a no-op, and audits nothing, when the type is already what was asked for', function () {
    $account = testBusinessAccount();

    $this->actingAs($this->staff)->post(route('admin.accounts.account-type.update', $account), [
        'account_type' => AccountType::Conditional->value,
        'reason' => 'Confirming the default.',
    ])->assertSessionHasNoErrors();

    expect($account->fresh()->account_type)->toBe(AccountType::Conditional)
        ->and(AuditLog::query()->where('action', 'account.account_type_changed')->count())->toBe(0);
});

it('is refused without account.edit, and to a business-account session', function () {
    $account = testBusinessAccount();
    $payload = ['account_type' => AccountType::NonConditional->value, 'reason' => 'x'];

    $this->actingAs(testPlatformStaff(PlatformRole::OrderManager))
        ->post(route('admin.accounts.account-type.update', $account), $payload)
        ->assertForbidden();

    $this->actingAs(testBusinessAccount()->owner)
        ->post(route('admin.accounts.account-type.update', $account), $payload)
        ->assertForbidden();

    expect($account->fresh()->account_type)->toBe(AccountType::Conditional);
});
