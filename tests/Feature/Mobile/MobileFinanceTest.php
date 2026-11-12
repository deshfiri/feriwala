<?php

use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Enums\ClientAddressType;
use App\Domain\Address\Models\SharedAddress;
use App\Domain\Billing\Models\Payment;
use App\Domain\Payout\Actions\SavePayoutMethod;
use App\Domain\Payout\Enums\PayoutMethodStatus;
use App\Domain\Payout\Enums\PayoutMethodType;
use App\Domain\Payout\Enums\PayoutOwnerType;
use App\Domain\Payout\Models\PayoutMethod;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletTransactionStatus;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\WalletService;
use App\Domain\Withdrawal\Models\AccountWithdrawal;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/*
 * The mobile finance-support surface: wallet top-ups and statement export,
 * withdrawals, payout methods, addresses and the bank/location lookups.
 * Withdrawals run through the real RequestAccountWithdrawal action.
 */

beforeEach(function () {
    $this->account = testBusinessAccount(AccountStatus::Active);
    $this->owner = $this->account->owner;
    $this->wallet = app(OpenWallet::class)->handle($this->account);
});

function mobileFinanceFund(Wallet $wallet, string $amount): void
{
    app(WalletService::class)->credit(
        $wallet->refresh(),
        LedgerTransactionType::SalesCredit,
        Money::fromDecimal($amount, Currency::BDT),
        new PostingContext(source: 'test', description: 'Sales'),
    );
}

function mobileFinanceMethod(int $accountId, string $number = '01711112222'): PayoutMethod
{
    return app(SavePayoutMethod::class)->handle(
        ownerType: PayoutOwnerType::BusinessAccount,
        ownerId: $accountId,
        type: PayoutMethodType::Bkash,
        label: 'Primary bKash',
        details: ['account_holder_name' => 'Karim Traders', 'account_number' => $number],
    );
}

function mobileFinanceStaffMember(mixed $account): User
{
    $staff = User::factory()->create();
    $account->memberships()->create(['user_id' => $staff->id, 'role' => AccountRole::Staff]);

    return $staff;
}

function mobileFinanceGateway(): void
{
    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);
}

describe('wallet top-up and statement export', function () {
    test('the options say what a top-up must be and which gateways take it', function () {
        mobileFinanceGateway();
        Sanctum::actingAs($this->owner);

        $this->getJson(route('mobile.v1.wallet-topups.top-up.options'))
            ->assertOk()
            ->assertJsonPath('minimum.amount', '1.00')
            ->assertJsonPath('suggested.amount', '0.00')
            ->assertJsonPath('gateways.0.value', 'sslcommerz');
    });

    test('a top-up records a payment and hands back the gateway address', function () {
        mobileFinanceGateway();
        Http::fake(['*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://sandbox.example/redirect'])]);
        Sanctum::actingAs($this->owner);

        $this->postJson(route('mobile.v1.wallet-topups.top-up.store'), ['amount' => '5000.00', 'gateway' => 'sslcommerz'])
            ->assertOk()
            ->assertJsonPath('redirect_url', 'https://sandbox.example/redirect')
            ->assertJsonPath('plan.to_usable.amount', '5000.00')
            ->assertJsonPath('plan.purpose', 'wallet_top_up');

        expect(Payment::query()->firstOrFail()->amount->toDecimal())->toBe('5000.00');
    });

    test('an amount with excess precision or a gateway that is not enabled is refused', function () {
        mobileFinanceGateway();
        Sanctum::actingAs($this->owner);

        $this->postJson(route('mobile.v1.wallet-topups.top-up.store'), ['amount' => '10.555', 'gateway' => 'sslcommerz'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson(route('mobile.v1.wallet-topups.top-up.store'), ['amount' => '100.00', 'gateway' => 'nowhere'])
            ->assertUnprocessable()->assertJsonValidationErrors('gateway');

        expect(Payment::query()->count())->toBe(0);
    });

    test('the statement downloads as a CSV with exact decimal money', function () {
        mobileFinanceFund($this->wallet, '250.75');
        Sanctum::actingAs($this->owner);

        $response = $this->get(route('mobile.v1.wallet-topups.statement.export'), ['Accept' => 'text/csv'])
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        expect($response->streamedContent())->toContain('Balance after')->toContain('250.75');
    });

    test('top-up is closed until the account is activated', function () {
        $pending = testBusinessAccount(AccountStatus::PaymentVerificationPending);
        Sanctum::actingAs($pending->owner);

        $this->getJson(route('mobile.v1.wallet-topups.top-up.options'))
            ->assertForbidden()->assertJsonPath('code', 'account_not_activated');
    });
});

describe('withdrawals', function () {
    test('the options carry the server\'s balance, limits and active methods', function () {
        mobileFinanceFund($this->wallet, '1000.00');
        $method = mobileFinanceMethod($this->account->id);
        Sanctum::actingAs($this->owner);

        $this->getJson(route('mobile.v1.withdrawals.options'))
            ->assertOk()
            ->assertJsonPath('available_balance.amount', '1000.00')
            ->assertJsonPath('minimum.amount', '500.00')
            ->assertJsonPath('maximum', null)
            ->assertJsonPath('methods.0.id', $method->public_id)
            ->assertJsonPath('methods.0.masked_number', '••••2222');
    });

    test('a request reserves the money through the real action and a retry reserves nothing more', function () {
        mobileFinanceFund($this->wallet, '1000.00');
        $method = mobileFinanceMethod($this->account->id);
        Sanctum::actingAs($this->owner);

        $payload = ['payout_method_id' => $method->public_id, 'amount' => '600.00'];

        $id = $this->postJson(route('mobile.v1.withdrawals.store'), $payload, ['Idempotency-Key' => 'abc-123'])
            ->assertCreated()
            ->assertJsonPath('data.amount.amount', '600.00')
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.payout.masked_number', '••••2222')
            ->assertJsonPath('data.history.0.new_status', 'Requested')
            ->json('data.id');

        $withdrawal = AccountWithdrawal::query()->where('public_id', $id)->firstOrFail();
        expect($withdrawal->business_account_id)->toBe($this->account->id)
            ->and($withdrawal->walletTransaction->status)->toBe(WalletTransactionStatus::Pending)
            ->and($this->wallet->refresh()->reserved->toDecimal())->toBe('600.00');

        $this->postJson(route('mobile.v1.withdrawals.store'), $payload, ['Idempotency-Key' => 'abc-123'])
            ->assertOk()
            ->assertJsonPath('data.id', $id);

        expect(AccountWithdrawal::query()->count())->toBe(1)
            ->and($this->wallet->refresh()->reserved->toDecimal())->toBe('600.00');
    });

    test('an amount above what is withdrawable, or below the minimum, is refused on amount', function () {
        mobileFinanceFund($this->wallet, '1000.00');
        $method = mobileFinanceMethod($this->account->id);
        Sanctum::actingAs($this->owner);

        $this->postJson(route('mobile.v1.withdrawals.store'), ['payout_method_id' => $method->public_id, 'amount' => '1000.01'], ['Idempotency-Key' => 'k1'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson(route('mobile.v1.withdrawals.store'), ['payout_method_id' => $method->public_id, 'amount' => '100.00'], ['Idempotency-Key' => 'k2'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');

        expect(AccountWithdrawal::query()->count())->toBe(0)
            ->and($this->wallet->refresh()->reserved->toDecimal())->toBe('0.00');
    });

    test('a request without an idempotency key is refused', function () {
        mobileFinanceFund($this->wallet, '1000.00');
        $method = mobileFinanceMethod($this->account->id);
        Sanctum::actingAs($this->owner);

        $this->postJson(route('mobile.v1.withdrawals.store'), ['payout_method_id' => $method->public_id, 'amount' => '600.00'])
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    });

    test('another account\'s payout method or withdrawal is a 404', function () {
        mobileFinanceFund($this->wallet, '1000.00');
        $other = testBusinessAccount(AccountStatus::Active);
        mobileFinanceFund(app(OpenWallet::class)->handle($other), '1000.00');
        $theirMethod = mobileFinanceMethod($other->id, '01799998888');

        Sanctum::actingAs($other->owner);
        $theirWithdrawal = $this->postJson(route('mobile.v1.withdrawals.store'), ['payout_method_id' => $theirMethod->public_id, 'amount' => '600.00'], ['Idempotency-Key' => 'theirs'])
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($this->owner);

        $this->postJson(route('mobile.v1.withdrawals.store'), ['payout_method_id' => $theirMethod->public_id, 'amount' => '600.00'], ['Idempotency-Key' => 'mine'])
            ->assertNotFound();
        $this->getJson(route('mobile.v1.withdrawals.show', $theirWithdrawal))->assertNotFound();
        $this->getJson(route('mobile.v1.withdrawals.index'))->assertOk()->assertJsonCount(0, 'data');
    });

    test('a member who does not run the business may not request one', function () {
        mobileFinanceFund($this->wallet, '1000.00');
        $method = mobileFinanceMethod($this->account->id);
        Sanctum::actingAs(mobileFinanceStaffMember($this->account));

        $this->postJson(route('mobile.v1.withdrawals.store'), ['payout_method_id' => $method->public_id, 'amount' => '600.00'], ['Idempotency-Key' => 'x'])
            ->assertForbidden();
        $this->getJson(route('mobile.v1.withdrawals.options'))->assertForbidden();
    });

    test('the list is filterable by status and a withdrawal is shown in full', function () {
        mobileFinanceFund($this->wallet, '1000.00');
        $method = mobileFinanceMethod($this->account->id);
        Sanctum::actingAs($this->owner);
        $id = $this->postJson(route('mobile.v1.withdrawals.store'), ['payout_method_id' => $method->public_id, 'amount' => '500.00'], ['Idempotency-Key' => 'a'])
            ->assertCreated()->json('data.id');

        $this->getJson(route('mobile.v1.withdrawals.index', ['status' => 'requested']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)->assertJsonPath('meta.total', 1);
        $this->getJson(route('mobile.v1.withdrawals.index', ['status' => 'paid']))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('mobile.v1.withdrawals.show', $id))->assertOk()->assertJsonPath('data.reference', AccountWithdrawal::query()->firstOrFail()->reference);
    });

    test('withdrawals are closed until the account is activated and need a token', function () {
        $pending = testBusinessAccount(AccountStatus::PaymentVerificationPending);
        Sanctum::actingAs($pending->owner);
        $this->getJson(route('mobile.v1.withdrawals.index'))->assertForbidden()->assertJsonPath('code', 'account_not_activated');
    });
});

test('the finance endpoints need a token', function () {
    $this->getJson(route('mobile.v1.withdrawals.index'))->assertUnauthorized();
});

describe('payout methods', function () {
    test('a method is added and listed with only its masked number', function () {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson(route('mobile.v1.payout-methods.store'), [
            'current_password' => 'password',
            'type' => 'bkash',
            'label' => 'Shop bKash',
            'details' => ['account_holder_name' => 'Karim Traders', 'account_number' => '01711112222'],
            'is_default' => true,
        ])->assertCreated()
            ->assertJsonPath('data.masked_number', '••••2222')
            ->assertJsonPath('data.is_default', true);

        expect($response->getContent())->not->toContain('01711112222');

        $this->getJson(route('mobile.v1.payout-methods.index'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('can.create', true)->assertJsonStructure(['types' => [['value', 'label', 'fields']]]);
    });

    test('a wrong password or mismatched account numbers are refused', function () {
        Sanctum::actingAs($this->owner);
        $payload = [
            'current_password' => 'password',
            'type' => 'bkash',
            'label' => 'Shop bKash',
            'details' => ['account_holder_name' => 'Karim Traders', 'account_number' => '01711112222'],
        ];

        $this->postJson(route('mobile.v1.payout-methods.store'), [...$payload, 'current_password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->postJson(route('mobile.v1.payout-methods.store'), [...$payload, 'details' => [...$payload['details'], 'confirm_account_number' => '01700000000']])
            ->assertUnprocessable()->assertJsonValidationErrors('details');

        expect(PayoutMethod::query()->count())->toBe(0);
    });

    test('another account\'s method cannot be changed, defaulted or archived', function () {
        $theirs = mobileFinanceMethod(testBusinessAccount(AccountStatus::Active)->id);
        Sanctum::actingAs($this->owner);

        $this->postJson(route('mobile.v1.payout-methods.archive', $theirs->public_id), ['current_password' => 'password'])->assertNotFound();
        $this->postJson(route('mobile.v1.payout-methods.default', $theirs->public_id))->assertNotFound();
        $this->putJson(route('mobile.v1.payout-methods.update', $theirs->public_id), [
            'current_password' => 'password', 'type' => 'bkash', 'label' => 'x',
            'details' => ['account_holder_name' => 'A', 'account_number' => '01711119999'],
        ])->assertNotFound();

        expect($theirs->refresh()->status)->toBe(PayoutMethodStatus::Active);
    });

    test('a method is archived with the password and a staff member may not add one', function () {
        $method = mobileFinanceMethod($this->account->id);
        Sanctum::actingAs($this->owner);

        $this->postJson(route('mobile.v1.payout-methods.archive', $method->public_id), ['current_password' => 'password'])
            ->assertOk()->assertJsonPath('data.is_active', false);
        expect($method->refresh()->status)->toBe(PayoutMethodStatus::Archived);

        Sanctum::actingAs(mobileFinanceStaffMember($this->account));
        $this->postJson(route('mobile.v1.payout-methods.store'), [
            'current_password' => 'password', 'type' => 'nagad', 'label' => 'N',
            'details' => ['account_holder_name' => 'A', 'account_number' => '01811112222'],
        ])->assertForbidden();
    });
});

describe('addresses', function () {
    test('an address is added, made default and archived', function () {
        $chain = addressTestLocationChain();
        Sanctum::actingAs($this->owner);

        $id = $this->postJson(route('mobile.v1.addresses.store'), [
            'type' => ClientAddressType::Business->value,
            'contact_name' => 'Karim Traders',
            'contact_mobile' => '+8801711111111',
            'division_id' => $chain['division']->id,
            'district_id' => $chain['district']->id,
            'upazila_id' => $chain['upazila']->id,
            'union_id' => $chain['union']->id,
            'detailed_address' => 'House 1, Road 2',
            'is_default' => true,
        ])->assertCreated()
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.location.district.name_en', 'Test District')
            ->json('data.id');

        $this->getJson(route('mobile.v1.addresses.index'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('can.create', true);
        $this->postJson(route('mobile.v1.addresses.default', $id))->assertOk()->assertJsonPath('data.is_default', true);
        $this->postJson(route('mobile.v1.addresses.archive', $id))->assertOk()->assertJsonPath('data.is_active', false);
    });

    test('a union outside the chosen upazila is refused', function () {
        $chain = addressTestLocationChain();
        $otherChain = addressTestLocationChain();
        Sanctum::actingAs($this->owner);

        $this->postJson(route('mobile.v1.addresses.store'), [
            'type' => ClientAddressType::Business->value,
            'contact_name' => 'K',
            'contact_mobile' => '+8801711111111',
            'division_id' => $chain['division']->id,
            'district_id' => $chain['district']->id,
            'upazila_id' => $chain['upazila']->id,
            'union_id' => $otherChain['union']->id,
            'detailed_address' => 'House 1',
        ])->assertUnprocessable()->assertJsonValidationErrors('union_id');
    });

    test('another account\'s address is a 404 and a staff member may not add one', function () {
        $other = testBusinessAccount(AccountStatus::Active);
        $theirs = addressTestCreate(AddressOwnerType::BusinessAccount, $other->id);
        $chain = addressTestLocationChain();
        Sanctum::actingAs($this->owner);

        $this->postJson(route('mobile.v1.addresses.archive', $theirs->public_id))->assertNotFound();
        $this->postJson(route('mobile.v1.addresses.default', $theirs->public_id))->assertNotFound();
        expect(SharedAddress::query()->findOrFail($theirs->id)->status->value)->toBe('active');

        Sanctum::actingAs(mobileFinanceStaffMember($this->account));
        $this->postJson(route('mobile.v1.addresses.store'), [
            'type' => ClientAddressType::Business->value,
            'contact_name' => 'K',
            'contact_mobile' => '+8801711111111',
            'division_id' => $chain['division']->id,
            'district_id' => $chain['district']->id,
            'upazila_id' => $chain['upazila']->id,
            'detailed_address' => 'House 1',
        ])->assertForbidden();
    });
});

describe('bank and location lookups', function () {
    test('banks, then a bank\'s branches in a district', function () {
        $branch = payoutTestBankBranch();
        $chain = addressTestLocationChain();
        Sanctum::actingAs($this->owner);

        $this->getJson(route('mobile.v1.banks.index'))
            ->assertOk()->assertJsonPath('data.0.bank_code', '001');
        $this->getJson(route('mobile.v1.banks.branches', ['bankCode' => '001', 'district_id' => $chain['district']->id]))
            ->assertOk()->assertJsonPath('data', []);

        expect($branch->routing_number)->toBe('001120100');

        // The bank list carries every identifier and name, and the whole directory is one request away.
        $this->getJson(route('mobile.v1.banks.index'))
            ->assertJsonPath('data.0.id', $branch->bank_id)
            ->assertJsonStructure(['data' => [['id', 'bank_code', 'name', 'slug', 'aliases', 'branch_count']]]);

        $all = $this->getJson(route('mobile.v1.banks.index', ['include' => 'branches']))->assertOk();
        $all->assertJsonPath('data.0.branches.0.routing_number', $branch->routing_number)
            ->assertJsonPath('data.0.branches.0.id', $branch->id)
            ->assertJsonPath('data.0.branches.0.bank_code', '001')
            ->assertJsonStructure(['data' => [['branches' => [[
                'id', 'bank_code', 'routing_number', 'name', 'slug', 'branch_code', 'original_branch_code',
                'district_id', 'district_name_en', 'district_name_bn', 'district_source_name',
                'swift_code', 'address', 'telephone', 'email', 'fax',
            ]]]]]);

        // Without a district, every branch of the bank; with one, only that district's.
        $this->getJson(route('mobile.v1.banks.branches', '001'))
            ->assertOk()->assertJsonPath('data.0.routing_number', $branch->routing_number);
    });

    test('divisions, then the places inside a place', function () {
        $chain = addressTestLocationChain();
        Sanctum::actingAs($this->owner);

        $this->getJson(route('mobile.v1.locations.divisions'))
            ->assertOk()->assertJsonPath('data.0.name_en', 'Test Division');
        $this->getJson(route('mobile.v1.locations.children', ['type' => 'division', 'sourceId' => $chain['division']->source_id]))
            ->assertOk()->assertJsonPath('data.0.id', $chain['district']->id);
        // The plural a client naturally builds from "divisions" resolves the same way.
        $this->getJson(route('mobile.v1.locations.children', ['type' => 'divisions', 'sourceId' => $chain['division']->source_id]))
            ->assertOk()->assertJsonPath('data.0.id', $chain['district']->id);
        $this->getJson(route('mobile.v1.locations.children', ['type' => 'nonsense', 'sourceId' => 'x']))->assertNotFound();
    });
});
