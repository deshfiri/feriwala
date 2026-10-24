<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Supplier\Actions\OpenSupplierWallet;
use App\Domain\Supplier\Data\SupplierPostingContext;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\SupplierWalletService;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\WalletService;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/*
 * The deployment check for typed wallet balances.
 *
 * What the migration promises is that every balance that existed before is
 * withdrawable afterwards and that nothing in either ledger was touched. This
 * command proves both: a snapshot before, a comparison after, and a non-zero exit
 * the moment anything differs.
 */

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/typed-balances-'.bin2hex(random_bytes(4));
    mkdir($this->directory, 0700);
    $this->snapshot = $this->directory.'/before.json';

    // A legacy account wallet and a legacy Supplier wallet: money that was there
    // before typed balances, earned rather than topped up.
    $this->wallet = app(OpenWallet::class)->handle(testBusinessAccount(AccountStatus::Active));
    app(WalletService::class)->credit(
        $this->wallet,
        LedgerTransactionType::SalesCredit,
        Money::fromDecimal('1200.00', Currency::BDT),
        new PostingContext(source: 'test', description: 'Earned before typed balances'),
    );

    $this->supplierWallet = app(OpenSupplierWallet::class)->handle(Supplier::factory()->create(), Currency::BDT);
    app(SupplierWalletService::class)->credit(
        $this->supplierWallet,
        Money::fromDecimal('800.00', Currency::BDT),
        new SupplierPostingContext(source: 'test', description: 'Earned before typed balances', idempotencyKey: 'legacy-settlement'),
    );
});

afterEach(function () {
    foreach (glob($this->directory.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->directory);
});

it('passes when every balance is identical and every one is withdrawable', function () {
    $this->artisan('wallets:verify-typed-balances', ['--snapshot' => $this->snapshot])->assertSuccessful();

    $this->artisan('wallets:verify-typed-balances', ['--against' => $this->snapshot])
        ->expectsOutputToContain('Unexpected changes: 0')
        ->assertSuccessful();

    expect(decoct(fileperms($this->snapshot) & 0777))->toBe('600');
});

it('fails when a balance changed', function () {
    $this->artisan('wallets:verify-typed-balances', ['--snapshot' => $this->snapshot])->assertSuccessful();

    DB::table('wallets')->where('id', $this->wallet->id)->update(['total' => '1199.99']);

    $this->artisan('wallets:verify-typed-balances', ['--against' => $this->snapshot])->assertFailed();
});

it('fails when a Supplier balance changed', function () {
    $this->artisan('wallets:verify-typed-balances', ['--snapshot' => $this->snapshot])->assertSuccessful();

    DB::table('supplier_wallets')->where('id', $this->supplierWallet->id)->update(['total' => '799.99']);

    $this->artisan('wallets:verify-typed-balances', ['--against' => $this->snapshot])->assertFailed();
});

it('fails when a ledger gained an entry the snapshot never saw', function () {
    $this->artisan('wallets:verify-typed-balances', ['--snapshot' => $this->snapshot])->assertSuccessful();

    app(WalletService::class)->credit(
        $this->wallet->refresh(),
        LedgerTransactionType::SalesCredit,
        Money::fromDecimal('1.00', Currency::BDT),
        new PostingContext(source: 'test', description: 'Another'),
    );

    $this->artisan('wallets:verify-typed-balances', ['--against' => $this->snapshot])->assertFailed();
});

it('fails when a balance that existed before has become platform credit', function () {
    $this->artisan('wallets:verify-typed-balances', ['--snapshot' => $this->snapshot])->assertSuccessful();

    // Platform credit with no entry in the ledger to explain it.
    DB::table('wallets')->where('id', $this->wallet->id)->update(['platform_credit' => '500.00']);

    $this->artisan('wallets:verify-typed-balances', ['--against' => $this->snapshot])
        ->expectsOutputToContain('BALANCE BECAME PLATFORM CREDIT')
        ->assertFailed();
});

it('keeps every legacy balance exactly as withdrawable as it was', function () {
    expect($this->wallet->refresh()->withdrawableHolding()->toDecimal())->toBe('1200.00')
        ->and($this->wallet->platformCredit()->toDecimal())->toBe('0.00')
        ->and($this->supplierWallet->refresh()->withdrawableHolding()->toDecimal())->toBe('800.00')
        ->and($this->supplierWallet->platformCredit()->toDecimal())->toBe('0.00');
});

it('refuses to write a production snapshot inside the repository', function () {
    $this->artisan('wallets:verify-typed-balances', ['--snapshot' => base_path('typed-balances.json')])->assertFailed();

    expect(file_exists(base_path('typed-balances.json')))->toBeFalse();
});

it('asks for one of its two options', function () {
    $this->artisan('wallets:verify-typed-balances')->assertExitCode(2);
});
