<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Payout\Actions\SavePayoutMethod;
use App\Domain\Payout\Enums\PayoutMethodType;
use App\Domain\Payout\Enums\PayoutOwnerType;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\WalletService;
use App\Domain\Withdrawal\Actions\RequestAccountWithdrawal;
use App\Domain\Withdrawal\Enums\AccountWithdrawalStatus;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/*
 * Staff-side Client/Partner withdrawal decisions (§27), mirroring
 * tests/Feature/Supplier/SupplierFinanceAdminTest.php's `withdrawal decisions`
 * and `guard and self-scope isolation` sections for the generic Wallet-backed
 * side. Every money-moving route sits behind `RequirePassword`; every route
 * is gated by the existing `Module::Withdrawal` permissions, never an
 * account-specific one invented for this batch.
 */
beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->wallet = app(OpenWallet::class)->handle(testBusinessAccount(AccountStatus::Active));
    app(WalletService::class)->credit(
        $this->wallet,
        LedgerTransactionType::TopUpCredit,
        Money::fromDecimal('1000.00', Currency::BDT),
        new PostingContext(source: 'test', description: 'Opening top-up'),
    );
    $this->account = $this->wallet->businessAccount;

    $method = app(SavePayoutMethod::class)->handle(
        ownerType: PayoutOwnerType::BusinessAccount,
        ownerId: $this->account->id,
        type: PayoutMethodType::Bkash,
        label: 'bKash',
        details: ['account_holder_name' => 'Test', 'account_number' => '01711112222'],
    );

    $this->withdrawal = app(RequestAccountWithdrawal::class)->handle(
        $this->account, $this->wallet->refresh(), $method, Money::fromDecimal('500.00', Currency::BDT), 'admin-test:withdrawal',
    );

    $this->approver = testPlatformStaff(PlatformRole::WithdrawalApprover);
});

describe('withdrawal decisions', function () {
    it('lets a decider without release-payment approve and process, but refuses release-payment to it', function () {
        // SupplierManager holds View/Edit/Approve/Reject on Module::Withdrawal
        // but never ReleasePayment (`.ai/rules/withdrawal.md`'s own separation
        // of duties, reused verbatim rather than invented for this module).
        $decider = testPlatformStaff(PlatformRole::SupplierManager);

        $this->actingAs($decider)
            ->post(route('admin.account-withdrawals.approve', $this->withdrawal->public_id))
            ->assertRedirect();

        expect($this->withdrawal->fresh()->status)->toBe(AccountWithdrawalStatus::Approved);

        $this->actingAs($decider)
            ->post(route('admin.account-withdrawals.process', $this->withdrawal->public_id))
            ->assertRedirect();

        expect($this->withdrawal->fresh()->status)->toBe(AccountWithdrawalStatus::Processing);

        $this->actingAs($decider)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.account-withdrawals.paid', $this->withdrawal->public_id), [
                'external_reference' => 'BKASH-TXN-1',
            ])
            ->assertForbidden();

        expect($this->withdrawal->fresh()->status)->toBe(AccountWithdrawalStatus::Processing);
    });

    it('lets a WithdrawalApprover release a payment, only once confirmed with a password, capturing the claim', function () {
        $this->actingAs($this->approver)->post(route('admin.account-withdrawals.approve', $this->withdrawal->public_id));
        $this->actingAs($this->approver)->post(route('admin.account-withdrawals.process', $this->withdrawal->public_id));

        $this->actingAs($this->approver)
            ->post(route('admin.account-withdrawals.paid', $this->withdrawal->public_id), [
                'external_reference' => 'BKASH-TXN-1',
            ])
            ->assertRedirect(route('password.confirm'));

        $this->actingAs($this->approver)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.account-withdrawals.paid', $this->withdrawal->public_id), [
                'external_reference' => 'BKASH-TXN-1',
            ])
            ->assertRedirect();

        $fresh = $this->withdrawal->fresh();
        expect($fresh->status)->toBe(AccountWithdrawalStatus::Paid)
            ->and($fresh->external_reference)->toBe('BKASH-TXN-1')
            ->and($fresh->walletTransaction->status->value)->toBe('settled');

        $wallet = Wallet::query()->findOrFail($this->wallet->id);
        expect($wallet->reserved->toDecimal())->toBe('0.00')
            ->and($wallet->total->toDecimal())->toBe('500.00');
    });

    it('releases the reservation exactly once on rejection, with a reason', function () {
        expect($this->wallet->refresh()->reserved->toDecimal())->toBe('500.00');

        $this->actingAs($this->approver)
            ->post(route('admin.account-withdrawals.reject', $this->withdrawal->public_id), [
                'reason' => 'Payout details could not be verified.',
            ])
            ->assertRedirect();

        expect($this->withdrawal->fresh()->status)->toBe(AccountWithdrawalStatus::Rejected)
            ->and(Wallet::query()->findOrFail($this->wallet->id)->reserved->toDecimal())->toBe('0.00');
    });
});

describe('guard and self-scope isolation', function () {
    it('never lets an activated Client/Partner business owner open the staff withdrawal queue', function () {
        // This is the exact shape of bug caught in review: viewAny()/view()
        // must never treat "has a business account" as staff access.
        $this->actingAs($this->account->owner)
            ->get(route('admin.account-withdrawals.index'))
            ->assertForbidden();

        $this->actingAs($this->account->owner)
            ->get(route('admin.account-withdrawals.show', $this->withdrawal->public_id))
            ->assertForbidden();
    });

    it('refuses the staff queue to a role without withdrawal.view', function () {
        $noAccess = testPlatformStaff(PlatformRole::PackageManager);

        $this->actingAs($noAccess)->get(route('admin.account-withdrawals.index'))->assertForbidden();
        $this->actingAs($noAccess)->get(route('admin.account-withdrawals.show', $this->withdrawal->public_id))->assertForbidden();
    });

    it('shows the staff queue and detail to a role holding withdrawal.view', function () {
        $this->actingAs($this->approver)->get(route('admin.account-withdrawals.index'))->assertOk();
        $this->actingAs($this->approver)->get(route('admin.account-withdrawals.show', $this->withdrawal->public_id))->assertOk();
    });

    it('never lets a Supplier session reach any staff account-withdrawal route', function () {
        $supplier = Supplier::factory()->create();
        supplierTestSignIn($supplier);

        $this->get(route('admin.account-withdrawals.index'))->assertRedirect(route('login'));
        $this->get(route('admin.account-withdrawals.show', $this->withdrawal->public_id))->assertRedirect(route('login'));
        $this->post(route('admin.account-withdrawals.approve', $this->withdrawal->public_id))->assertRedirect(route('login'));
    });

    it('lets a Client/Partner reach only its own withdrawal through the Erp self-service route', function () {
        $this->actingAs($this->account->owner)
            ->get(route('withdrawals.show', $this->withdrawal->public_id))
            ->assertOk();
    });

    it('never lets one Client/Partner reach another account\'s withdrawal through the Erp self-service route', function () {
        $otherOwner = testBusinessAccount(AccountStatus::Active)->owner;

        $this->actingAs($otherOwner)
            ->get(route('withdrawals.show', $this->withdrawal->public_id))
            ->assertNotFound();
    });
});
