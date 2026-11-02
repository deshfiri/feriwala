<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\BalanceType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\WalletService;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Laravel\Sanctum\Sanctum;

/*
 * The mobile wallet (§23, §31.3): the same figures as the web screen, reached
 * only through the signed-in person's own account.
 */

beforeEach(function () {
    $this->account = testBusinessAccount(AccountStatus::Active);
    $this->owner = $this->account->owner;
    $this->wallet = app(OpenWallet::class)->handle($this->account);
});

function mobileWalletCredit(Wallet $wallet, string $amount, string $description = 'Top-up'): WalletTransaction
{
    return app(WalletService::class)->credit(
        $wallet->refresh(),
        LedgerTransactionType::TopUpCredit,
        Money::fromDecimal($amount, Currency::BDT),
        new PostingContext(source: 'test', description: $description),
        BalanceType::Withdrawable,
    );
}

test('the wallet carries flat Taka as exact decimal strings', function () {
    mobileWalletCredit($this->wallet, '500.50');

    Sanctum::actingAs($this->owner);

    $this->getJson(route('mobile.v1.wallet.show'))
        ->assertOk()
        ->assertJsonPath('data.total.amount', '500.50')
        ->assertJsonPath('data.total.currency', 'BDT')
        ->assertJsonPath('data.usable.amount', '500.50')
        ->assertJsonPath('data.withdrawable.formatted', '৳500.50')
        ->assertJsonPath('restrictions', []);
});

test('an empty wallet is zero rather than nothing', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson(route('mobile.v1.wallet.show'))->assertOk()->assertJsonPath('data.total.amount', '0.00');
    $this->getJson(route('mobile.v1.wallet.transactions.index'))->assertOk()->assertJsonPath('meta.total', 0);
});

test('the usable balance is never cached', function () {
    mobileWalletCredit($this->wallet, '120.00');
    Sanctum::actingAs($this->owner);

    $this->getJson(route('mobile.v1.wallet.usable'))
        ->assertOk()
        ->assertJsonPath('usable.amount', '120.00')
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('the statement lists movements newest first, paginated and filterable', function () {
    mobileWalletCredit($this->wallet, '100.00', 'First');
    mobileWalletCredit($this->wallet, '200.00', 'Second');
    Sanctum::actingAs($this->owner);

    $this->getJson(route('mobile.v1.wallet.transactions.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.description', 'Second')
        ->assertJsonPath('data.0.credit.amount', '200.00')
        ->assertJsonPath('data.0.balance_after.amount', '300.00')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonStructure(['links', 'filters', 'options' => ['types', 'statuses', 'directions']]);

    $this->getJson(route('mobile.v1.wallet.transactions.index', ['type' => 'nonsense']))
        ->assertOk()
        ->assertJsonPath('filters.type', '')
        ->assertJsonCount(2, 'data');
});

test('a movement is shown with its ledger entries and never its internal note', function () {
    $transaction = mobileWalletCredit($this->wallet, '100.00');
    $transaction->forceFill(['internal_note' => 'staff only'])->save();
    Sanctum::actingAs($this->owner);

    $response = $this->getJson(route('mobile.v1.wallet.transactions.show', $transaction->public_id))
        ->assertOk()
        ->assertJsonPath('data.id', $transaction->public_id)
        ->assertJsonPath('data.entries.0.credit.amount', '100.00');

    expect($response->getContent())->not->toContain('staff only')->not->toContain('internal_note');
});

test('nobody reaches another account\'s movement by borrowing its public id', function () {
    $other = testBusinessAccount(AccountStatus::Active);
    $theirs = mobileWalletCredit(app(OpenWallet::class)->handle($other), '900.00');

    Sanctum::actingAs($this->owner);

    $this->getJson(route('mobile.v1.wallet.transactions.show', $theirs->public_id))->assertNotFound();
});

test('an account that is not activated is turned back before it can read a wallet', function () {
    $pending = testBusinessAccount(AccountStatus::PaymentVerificationPending);
    Sanctum::actingAs($pending->owner);

    $this->getJson(route('mobile.v1.wallet.show'))
        ->assertForbidden()
        ->assertJsonPath('code', 'account_not_activated');
});

test('a login with no business account has no wallet to read', function () {
    Sanctum::actingAs(User::factory()->staff()->create());

    $this->getJson(route('mobile.v1.wallet.show'))
        ->assertForbidden()
        ->assertJsonPath('code', 'no_business_account');
});

test('the wallet needs a token', function () {
    $this->getJson(route('mobile.v1.wallet.show'))->assertUnauthorized();
});
