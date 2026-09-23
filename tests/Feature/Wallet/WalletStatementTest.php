<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Wallet\Actions\CorrectLedgerEntry;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerDirection;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\WalletService;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The account's own wallet screen (P2-8, §33.7, §31.3).
 *
 * Two things are being tested at once here: that the screen says what §33.7 asks
 * a financial screen to say, and that it says it only to the account it belongs
 * to. The second is the one that matters most — a statement is somebody's whole
 * commercial history.
 */

beforeEach(function () {
    $this->account = testBusinessAccount(AccountStatus::Active);
    $this->owner = $this->account->owner;
    $this->wallet = app(OpenWallet::class)->handle($this->account);
});

function statementCredit(Wallet $wallet, string $amount, string $description = 'Top-up'): WalletTransaction
{
    return app(WalletService::class)->credit(
        $wallet->refresh(),
        LedgerTransactionType::TopUpCredit,
        Money::fromDecimal($amount, Currency::BDT),
        new PostingContext(source: 'test', description: $description),
    );
}

describe('the wallet screen', function () {
    it('tells the buckets apart', function () {
        // §33.7 asks for these to be distinguishable. They are not synonyms:
        // the total includes money already spoken for.
        statementCredit($this->wallet, '500.00');

        $this->actingAs($this->owner)
            ->get(route('wallet.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('wallet/show')
                ->where('balances.total.amount', '500.00')
                ->where('balances.usable.amount', '500.00')
                ->where('balances.available_for_withdrawal.amount', '500.00')
                ->has('balances.reserved')
                ->has('balances.pending')
                ->has('balances.hold')
                ->has('balances.cod_receivable')
                ->has('balances.required_deposit'));
    });

    it('renders an empty wallet as zero rather than as nothing', function () {
        /*
         * A newly activated account has an empty wallet, and that is a normal
         * state. If the screen were blank it would read as a broken feature.
         */
        $this->actingAs($this->owner)
            ->get(route('wallet.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('balances.total.amount', '0.00')
                ->where('transactions.total', 0)
                ->has('transactions.data', 0));
    });

    it('lists movements newest first', function () {
        statementCredit($this->wallet, '100.00', 'First');
        statementCredit($this->wallet, '200.00', 'Second');

        $this->actingAs($this->owner)
            ->get(route('wallet.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('transactions.data', 2)
                ->where('transactions.data.0.description', 'Second')
                ->where('transactions.data.0.credit.amount', '200.00')
                ->where('transactions.data.0.balance_after.amount', '300.00')
                ->where('transactions.data.0.status_label', 'Settled'));
    });

    it('shows a claim as a movement with no figures', function () {
        /*
         * A reservation moves nothing and writes no ledger entry, but it is why
         * the spendable balance is lower than the total — so it belongs on the
         * statement, saying so through its status rather than a blank row.
         */
        statementCredit($this->wallet, '500.00');

        app(WalletService::class)->reserve(
            $this->wallet->refresh(),
            LedgerTransactionType::ServiceFeeDebit,
            Money::fromDecimal('200.00', Currency::BDT),
            new PostingContext(source: 'test', description: 'Reserved for a charge'),
        );

        $this->actingAs($this->owner)
            ->get(route('wallet.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('balances.total.amount', '500.00')
                ->where('balances.usable.amount', '300.00')
                ->where('transactions.data.0.debit', null)
                ->where('transactions.data.0.balance_after', null)
                ->where('transactions.data.0.status_label', 'Pending'));
    });

    it('filters by type', function () {
        statementCredit($this->wallet, '100.00', 'A top-up');

        app(WalletService::class)->credit(
            $this->wallet->refresh(),
            LedgerTransactionType::PromotionalCredit,
            Money::fromDecimal('50.00', Currency::BDT),
            new PostingContext(source: 'test', description: 'A promotion'),
        );

        $this->actingAs($this->owner)
            ->get(route('wallet.show', ['type' => 'promotional_credit']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('transactions.data', 1)
                ->where('transactions.data.0.description', 'A promotion')
                ->where('filters.type', 'promotional_credit'));
    });

    it('drops a filter it does not recognise instead of failing', function () {
        // A mistyped query string should show an unfiltered statement, not a
        // 500 and not an empty one.
        statementCredit($this->wallet, '100.00');

        $this->actingAs($this->owner)
            ->get(route('wallet.show', ['type' => 'nonsense', 'from' => 'not-a-date']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('transactions.data', 1)
                ->where('filters.type', '')
                ->where('filters.from', ''));
    });

    it('says plainly when the wallet is under its required deposit', function () {
        $this->wallet->forceFill(['required_deposit' => Money::fromDecimal('800.00', Currency::BDT)])->save();

        statementCredit($this->wallet, '500.00');

        $this->actingAs($this->owner)
            ->get(route('wallet.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('balances.meets_required_deposit', false)
                ->where('balances.shortfall.amount', '300.00'));
    });
});

describe('what a member may never see', function () {
    it('never sends a staff note to the account holder', function () {
        // §23.2's internal note is written by staff for staff, about the person
        // reading this screen.
        statementCredit($this->wallet, '1000.00');

        app(CorrectLedgerEntry::class)->adjust(
            $this->wallet->refresh(),
            User::factory()->staff()->create(),
            Money::fromDecimal('25.00', Currency::BDT),
            LedgerDirection::Credit,
            'Goodwill after a courier failure.',
            'Customer complained loudly on social media.',
        );

        $response = $this->actingAs($this->owner)->get(route('wallet.show'));

        $response->assertOk();
        expect($response->getContent())->not->toContain('social media');
    });

    it('cannot open another account\'s movement', function () {
        /*
         * Self-scoped by construction: the identifier is looked up *within* the
         * reader's own wallet, so a borrowed one finds nothing rather than
         * finding somebody else's money and being refused afterwards (§31.3).
         */
        $other = testBusinessAccount(AccountStatus::Active);
        $otherWallet = app(OpenWallet::class)->handle($other);
        $theirs = statementCredit($otherWallet, '500.00');

        $this->actingAs($this->owner)
            ->get(route('wallet.transactions.show', $theirs->public_id))
            ->assertNotFound();
    });

    it('has nothing to show a platform staff member with no business', function () {
        /*
         * Turned back by the §5.4 funnel gate rather than refused: they have no
         * business account, so there is no wallet of theirs to look at. Their
         * route to somebody else's is the administration screen, behind
         * `wallet.view` — see WalletAdministrationTest.
         */
        $this->seed(RolesAndPermissionsSeeder::class);

        $response = $this->actingAs(testPlatformStaff(PlatformRole::WalletManager))
            ->get(route('wallet.show'));

        $response->assertRedirect();
        expect($response->headers->get('location'))->not->toContain('/wallet');
    });
});

describe('the movement detail', function () {
    it('shows the entries behind a movement', function () {
        $transaction = statementCredit($this->wallet, '500.00');

        $this->actingAs($this->owner)
            ->get(route('wallet.transactions.show', $transaction->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('wallet/transaction')
                ->where('transaction.reference', $transaction->reference)
                ->has('transaction.entries', 1)
                ->where('transaction.entries.0.balance_before.amount', '0.00')
                ->where('transaction.entries.0.balance_after.amount', '500.00'));
    });
});

describe('the export (§33.7)', function () {
    it('hands over the statement as a file', function () {
        statementCredit($this->wallet, '500.00', 'A top-up');

        $response = $this->actingAs($this->owner)->get(route('wallet.download'));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        expect($csv)->toContain('Balance after')
            ->and($csv)->toContain('A top-up')
            // The exact decimal, not the display string: a spreadsheet cannot
            // add up "৳500.00".
            ->and($csv)->toContain('500.00');
    });

    it('honours the filters that were on screen', function () {
        statementCredit($this->wallet, '100.00', 'A top-up');

        app(WalletService::class)->credit(
            $this->wallet->refresh(),
            LedgerTransactionType::PromotionalCredit,
            Money::fromDecimal('50.00', Currency::BDT),
            new PostingContext(source: 'test', description: 'A promotion'),
        );

        $csv = $this->actingAs($this->owner)
            ->get(route('wallet.download', ['type' => 'promotional_credit']))
            ->streamedContent();

        expect($csv)->toContain('A promotion')
            ->and($csv)->not->toContain('A top-up');
    });

    it('will not let a description become a spreadsheet formula', function () {
        /*
         * A cell beginning with `=` is executed when the file is opened. The
         * description is text somebody else typed, so it is neutralised on the
         * way out.
         */
        statementCredit($this->wallet, '100.00', '=1+1');

        $csv = $this->actingAs($this->owner)
            ->get(route('wallet.download'))
            ->streamedContent();

        expect($csv)->toContain("'=1+1");
    });

    it('leaves the staff note out of the file entirely', function () {
        statementCredit($this->wallet, '1000.00');

        app(CorrectLedgerEntry::class)->adjust(
            $this->wallet->refresh(),
            User::factory()->staff()->create(),
            Money::fromDecimal('25.00', Currency::BDT),
            LedgerDirection::Credit,
            'Goodwill.',
            'Customer complained loudly on social media.',
        );

        $csv = $this->actingAs($this->owner)
            ->get(route('wallet.download'))
            ->streamedContent();

        expect($csv)->not->toContain('social media')
            ->and($csv)->not->toContain('Internal note');
    });
});
