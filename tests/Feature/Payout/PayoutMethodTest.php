<?php

use App\Domain\Account\Enums\AccountRole;
use App\Domain\Bank\Models\BdBank;
use App\Domain\Bank\Models\BdBankBranch;
use App\Domain\Payout\Actions\ArchivePayoutMethod;
use App\Domain\Payout\Actions\SavePayoutMethod;
use App\Domain\Payout\Actions\SetDefaultPayoutMethod;
use App\Domain\Payout\Enums\PayoutMethodStatus;
use App\Domain\Payout\Enums\PayoutMethodType;
use App\Domain\Payout\Enums\PayoutOwnerType;
use App\Domain\Payout\Exceptions\PayoutMethodRefused;
use App\Domain\Payout\Models\PayoutMethod;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The shared, owner-polymorphic payout method table (Shared Payout Methods
 * batch, D25, P13-24) — one architecture serving both Supplier and
 * Client/Partner BusinessAccount owners.
 */

function payoutTestBankBranch(string $bankCode = '001', string $routing = '001120100'): BdBankBranch
{
    $bank = BdBank::query()->firstOrCreate(
        ['bank_code' => $bankCode],
        ['name' => 'Test Bank '.$bankCode, 'slug' => 'test-bank-'.$bankCode, 'payable' => true, 'available_in_selector' => true, 'is_active' => true],
    );

    return BdBankBranch::create([
        'bank_id' => $bank->id,
        'routing_number' => $routing,
        'name' => 'Head Office',
        'slug' => 'head-office-'.$routing,
        'district_source_name' => 'Dhaka',
        'source' => 'test',
        'source_status' => 'legacy_unverified',
        'is_active' => true,
    ]);
}

it('creates a bKash method for a BusinessAccount and a Supplier independently, sharing the same table', function () {
    $account = testBusinessAccount();
    $supplier = Supplier::factory()->create();

    $accountMethod = app(SavePayoutMethod::class)->handle(
        ownerType: PayoutOwnerType::BusinessAccount,
        ownerId: $account->id,
        type: PayoutMethodType::Bkash,
        label: 'Business bKash',
        details: ['account_holder_name' => 'Karim Traders', 'account_number' => '01711112222'],
    );

    $supplierMethod = app(SavePayoutMethod::class)->handle(
        ownerType: PayoutOwnerType::Supplier,
        ownerId: $supplier->id,
        type: PayoutMethodType::Bkash,
        label: 'Supplier bKash',
        details: ['account_holder_name' => 'Supplier Co', 'account_number' => '01711112222'],
    );

    // Same account number, different owners -- never a collision.
    expect($accountMethod->id)->not->toBe($supplierMethod->id)
        ->and($accountMethod->owner_type)->toBe('business_account')
        ->and($supplierMethod->owner_type)->toBe('supplier')
        ->and(PayoutMethod::query()->count())->toBe(2);
});

it('never stores the raw account number outside the encrypted column', function () {
    $account = testBusinessAccount();

    $method = app(SavePayoutMethod::class)->handle(
        ownerType: PayoutOwnerType::BusinessAccount,
        ownerId: $account->id,
        type: PayoutMethodType::Nagad,
        label: 'Nagad',
        details: ['account_holder_name' => 'Karim Traders', 'account_number' => '01899990000'],
    );

    expect($method->last_four)->toBe('0000')
        ->and($method->maskedNumber())->toBe('••••0000')
        ->and(json_encode($method))->not->toContain('01899990000');

    $rawColumn = DB::table('payout_methods')->where('id', $method->id)->value('details');
    expect($rawColumn)->not->toContain('01899990000');
});

it('resolves a bank account method to the correct bank and branch', function () {
    $account = testBusinessAccount();
    $branch = payoutTestBankBranch();

    $method = app(SavePayoutMethod::class)->handle(
        ownerType: PayoutOwnerType::BusinessAccount,
        ownerId: $account->id,
        type: PayoutMethodType::BankAccount,
        label: 'Primary bank',
        details: ['account_holder_name' => 'Karim Traders', 'account_number' => '123456789012', 'account_type' => 'savings'],
        bdBankId: $branch->bank_id,
        bdBankBranchId: $branch->id,
    );

    expect($method->bd_bank_id)->toBe($branch->bank_id)
        ->and($method->bd_bank_branch_id)->toBe($branch->id)
        ->and($method->bank->bank_code)->toBe('001')
        ->and($method->branch->routing_number)->toBe('001120100');
});

it('snapshots district and routing number for a bank account method, and neither for a wallet method', function () {
    $account = testBusinessAccount();
    $branch = payoutTestBankBranch();

    $bankMethod = app(SavePayoutMethod::class)->handle(
        ownerType: PayoutOwnerType::BusinessAccount,
        ownerId: $account->id,
        type: PayoutMethodType::BankAccount,
        label: 'Primary bank',
        details: ['account_holder_name' => 'Karim Traders', 'account_number' => '123456789012', 'account_type' => 'savings'],
        bdBankId: $branch->bank_id,
        bdBankBranchId: $branch->id,
    );

    $bankSnapshot = $bankMethod->toSnapshot()->toArray();

    expect($bankSnapshot['district'])->toBe($branch->district_source_name)
        ->and($bankSnapshot['routing_number'])->toBe('001120100')
        ->and($bankSnapshot['bank_name'])->toBe($branch->bank->name)
        ->and($bankSnapshot['branch_name'])->toBe($branch->name);

    $walletMethod = app(SavePayoutMethod::class)->handle(
        ownerType: PayoutOwnerType::BusinessAccount,
        ownerId: $account->id,
        type: PayoutMethodType::Bkash,
        label: 'bKash',
        details: ['account_holder_name' => 'Karim Traders', 'account_number' => '01711112222'],
    );

    $walletSnapshot = $walletMethod->toSnapshot()->toArray();

    expect($walletSnapshot['district'])->toBeNull()
        ->and($walletSnapshot['routing_number'])->toBeNull()
        ->and($walletSnapshot['bank_name'])->toBeNull()
        ->and($walletSnapshot['branch_name'])->toBeNull();
});

it('refuses the same account number registered twice for the same owner', function () {
    $account = testBusinessAccount();

    app(SavePayoutMethod::class)->handle(
        ownerType: PayoutOwnerType::BusinessAccount,
        ownerId: $account->id,
        type: PayoutMethodType::Bkash,
        label: 'First',
        details: ['account_holder_name' => 'Karim Traders', 'account_number' => '01711112222'],
    );

    expect(fn () => app(SavePayoutMethod::class)->handle(
        ownerType: PayoutOwnerType::BusinessAccount,
        ownerId: $account->id,
        type: PayoutMethodType::Bkash,
        label: 'Second, same number',
        details: ['account_holder_name' => 'Karim Traders', 'account_number' => '01711112222'],
    ))->toThrow(PayoutMethodRefused::class);

    expect(PayoutMethod::query()->count())->toBe(1);
});

it('lets two different owners register the exact same account number without colliding', function () {
    $accountA = testBusinessAccount();
    $accountB = testBusinessAccount();

    app(SavePayoutMethod::class)->handle(
        ownerType: PayoutOwnerType::BusinessAccount,
        ownerId: $accountA->id,
        type: PayoutMethodType::Bkash,
        label: 'A',
        details: ['account_holder_name' => 'A', 'account_number' => '01711112222'],
    );

    $methodB = app(SavePayoutMethod::class)->handle(
        ownerType: PayoutOwnerType::BusinessAccount,
        ownerId: $accountB->id,
        type: PayoutMethodType::Bkash,
        label: 'B',
        details: ['account_holder_name' => 'B', 'account_number' => '01711112222'],
    );

    expect($methodB)->not->toBeNull()
        ->and(PayoutMethod::query()->count())->toBe(2);
});

it('allows re-registering an account number after the original was archived', function () {
    $account = testBusinessAccount();

    $method = app(SavePayoutMethod::class)->handle(
        ownerType: PayoutOwnerType::BusinessAccount,
        ownerId: $account->id,
        type: PayoutMethodType::Bkash,
        label: 'First',
        details: ['account_holder_name' => 'Karim Traders', 'account_number' => '01711112222'],
    );

    app(ArchivePayoutMethod::class)->handle($method);

    $second = app(SavePayoutMethod::class)->handle(
        ownerType: PayoutOwnerType::BusinessAccount,
        ownerId: $account->id,
        type: PayoutMethodType::Bkash,
        label: 'Corrected re-entry',
        details: ['account_holder_name' => 'Karim Traders', 'account_number' => '01711112222'],
    );

    expect($second->status)->toBe(PayoutMethodStatus::Active)
        ->and(PayoutMethod::query()->count())->toBe(2);
});

it('makes a new default clear every other default for that owner, never another owner\'s', function () {
    $accountA = testBusinessAccount();
    $accountB = testBusinessAccount();

    $a1 = app(SavePayoutMethod::class)->handle(PayoutOwnerType::BusinessAccount, $accountA->id, PayoutMethodType::Bkash, 'A1', ['account_holder_name' => 'A', 'account_number' => '01711110001'], makeDefault: true);
    $a2 = app(SavePayoutMethod::class)->handle(PayoutOwnerType::BusinessAccount, $accountA->id, PayoutMethodType::Bkash, 'A2', ['account_holder_name' => 'A', 'account_number' => '01711110002']);
    $b1 = app(SavePayoutMethod::class)->handle(PayoutOwnerType::BusinessAccount, $accountB->id, PayoutMethodType::Bkash, 'B1', ['account_holder_name' => 'B', 'account_number' => '01711110003'], makeDefault: true);

    app(SetDefaultPayoutMethod::class)->handle($a2->fresh());

    expect($a1->fresh()->is_default)->toBeFalse()
        ->and($a2->fresh()->is_default)->toBeTrue()
        ->and($b1->fresh()->is_default)->toBeTrue();
});

it('archives without deleting, and clears the default flag', function () {
    $account = testBusinessAccount();

    $method = app(SavePayoutMethod::class)->handle(
        ownerType: PayoutOwnerType::BusinessAccount,
        ownerId: $account->id,
        type: PayoutMethodType::Bkash,
        label: 'To archive',
        details: ['account_holder_name' => 'Karim Traders', 'account_number' => '01711112222'],
        makeDefault: true,
    );

    app(ArchivePayoutMethod::class)->handle($method);

    expect($method->fresh()->status)->toBe(PayoutMethodStatus::Archived)
        ->and($method->fresh()->is_default)->toBeFalse()
        ->and(PayoutMethod::query()->count())->toBe(1);
});

it('never deletes a payout method at the database level either', function () {
    $account = testBusinessAccount();

    $method = app(SavePayoutMethod::class)->handle(
        ownerType: PayoutOwnerType::BusinessAccount,
        ownerId: $account->id,
        type: PayoutMethodType::Bkash,
        label: 'Undeletable',
        details: ['account_holder_name' => 'Karim Traders', 'account_number' => '01711112222'],
    );

    expect(fn () => $method->delete())->toThrow(QueryException::class);
});

describe('Client/Partner authorization', function () {
    it('lets an account owner manage their own payout methods', function () {
        $account = testBusinessAccount();
        $owner = $account->owner;

        $this->actingAs($owner)
            ->postJson(route('payout-methods.store'), [
                'current_password' => 'password',
                'type' => PayoutMethodType::Bkash->value,
                'label' => 'Owner bKash',
                'details' => ['account_holder_name' => 'Karim Traders', 'account_number' => '01711112222', 'confirm_account_number' => '01711112222'],
            ])
            ->assertRedirect();

        expect(PayoutMethod::query()->where('owner_id', $account->id)->count())->toBe(1);
    });

    it('403s a staff member with an account membership but no update-account permission', function () {
        $account = testBusinessAccount();
        $staffUser = User::factory()->create();
        $account->memberships()->create(['user_id' => $staffUser->id, 'role' => AccountRole::Staff]);

        $this->actingAs($staffUser)
            ->postJson(route('payout-methods.store'), [
                'current_password' => 'whatever',
                'type' => PayoutMethodType::Bkash->value,
                'label' => 'Should be refused',
                'details' => ['account_holder_name' => 'X', 'account_number' => '01711119999', 'confirm_account_number' => '01711119999'],
            ])
            ->assertForbidden();

        expect(PayoutMethod::query()->count())->toBe(0);
    });

    it('refuses a mismatched account number confirmation', function () {
        $account = testBusinessAccount();
        $owner = $account->owner;

        $this->actingAs($owner)
            ->postJson(route('payout-methods.store'), [
                'current_password' => 'password',
                'type' => PayoutMethodType::Bkash->value,
                'label' => 'Mismatch',
                'details' => ['account_holder_name' => 'Karim Traders', 'account_number' => '01711112222', 'confirm_account_number' => '01711113333'],
            ])
            ->assertInvalid(['details']);

        expect(PayoutMethod::query()->count())->toBe(0);
    });

    it('never lets one BusinessAccount reach another\'s payout method by public id', function () {
        $accountA = testBusinessAccount();
        $accountB = testBusinessAccount();

        $methodA = app(SavePayoutMethod::class)->handle(
            ownerType: PayoutOwnerType::BusinessAccount,
            ownerId: $accountA->id,
            type: PayoutMethodType::Bkash,
            label: 'A only',
            details: ['account_holder_name' => 'A', 'account_number' => '01711110001'],
        );

        $this->actingAs($accountB->owner)
            ->postJson(route('payout-methods.default', $methodA->public_id))
            ->assertNotFound();
    });
});
