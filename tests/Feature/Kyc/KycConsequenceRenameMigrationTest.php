<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Kyc\Enums\KycConsequence;
use App\Domain\Kyc\Enums\KycRoundPurpose;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\KycRestrictions;
use App\Domain\Kyc\Models\KycSubmission;
use Illuminate\Support\Facades\DB;

/*
 * `block_wholesale_orders` → `block_new_orders` (§7.4).
 *
 * A fresh test schema never holds the old string, so the migration's data
 * rewrite is invisible to every other run in the suite. It is exercised here
 * directly, against a round written the way the previous release wrote them.
 *
 * What makes this worth a test rather than trusting the SQL: the failure mode
 * is silent. `KycSubmission::consequences()` reads with `tryFrom`, so an
 * unmigrated round does not error — it drops the value and stops restricting
 * a business that had been told it was restricted.
 */

/** The migration under test, loaded by path — it has no class name of its own. */
function consequenceRenameMigration(): object
{
    return require database_path(
        'migrations/2026_10_08_100000_merge_kyc_order_blocking_consequences.php'
    );
}

/** A re-verification round holding raw `$consequences`, written past the cast. */
function consequenceRenameRound(array $consequences): KycSubmission
{
    $account = testBusinessAccount(AccountStatus::Active);

    $round = KycSubmission::create([
        'business_account_id' => $account->id,
        'status' => KycStatus::Draft,
        'round' => 1,
        'purpose' => KycRoundPurpose::Reverification,
        'deadline_at' => now()->addDays(7),
        'consequences' => $consequences,
    ]);

    return $round->refresh();
}

it('rewrites a round still carrying the old consequence', function () {
    $round = consequenceRenameRound(['block_wholesale_orders']);

    consequenceRenameMigration()->up();

    expect($round->fresh()->consequences)->toBe(['block_new_orders']);
});

it('restores the restriction the old value had silently dropped', function () {
    // The point of the migration, stated as behaviour rather than as storage.
    $round = consequenceRenameRound(['block_wholesale_orders']);
    $account = $round->businessAccount;

    expect(app(KycRestrictions::class)->blocksNewOrders($account))->toBeFalse();

    consequenceRenameMigration()->up();

    // A fresh reader: the restriction cache is per-instance and was populated
    // by the assertion above, before the rewrite landed.
    expect(app(KycRestrictions::class)->blocksNewOrders($account->fresh()))->toBeTrue();
});

it('leaves the other consequences in the array alone, and in order', function () {
    $round = consequenceRenameRound([
        'block_publishing',
        'block_wholesale_orders',
        'block_withdrawals',
    ]);

    consequenceRenameMigration()->up();

    expect($round->fresh()->consequences)->toBe([
        'block_publishing',
        'block_new_orders',
        'block_withdrawals',
    ]);
});

it('does nothing to a round that never carried it', function () {
    $round = consequenceRenameRound([KycConsequence::BlockPublishing->value]);

    consequenceRenameMigration()->up();

    expect($round->fresh()->consequences)->toBe(['block_publishing']);
});

it('leaves a round with no consequences null rather than an empty array', function () {
    // `jsonb_agg` over no rows returns NULL, and the guard has to keep a
    // consequence-free round out of the UPDATE entirely — an empty array
    // would trip the "consequences need a deadline" check on some rounds.
    $account = testBusinessAccount(AccountStatus::Active);

    $round = KycSubmission::create([
        'business_account_id' => $account->id,
        'status' => KycStatus::Draft,
        'round' => 1,
        'purpose' => KycRoundPurpose::Reverification,
    ]);

    consequenceRenameMigration()->up();

    expect($round->fresh()->consequences)->toBeNull();
});

it('is idempotent — running it twice changes nothing further', function () {
    $round = consequenceRenameRound(['block_wholesale_orders']);

    consequenceRenameMigration()->up();
    consequenceRenameMigration()->up();

    expect($round->fresh()->consequences)->toBe(['block_new_orders']);
});

it('puts the old value back on the way down', function () {
    $round = consequenceRenameRound([KycConsequence::BlockNewOrders->value]);

    consequenceRenameMigration()->down();

    expect($round->fresh()->consequences)->toBe(['block_wholesale_orders']);
});

it('touches no round belonging to any other account', function () {
    // The UPDATE is unscoped by design — it is a storage rename — so the
    // guard that matters is that it only rewrites the one value.
    $untouched = consequenceRenameRound([KycConsequence::BlockWithdrawals->value]);
    $target = consequenceRenameRound(['block_wholesale_orders']);

    $before = DB::table('kyc_submissions')->count();

    consequenceRenameMigration()->up();

    expect($untouched->fresh()->consequences)->toBe(['block_withdrawals'])
        ->and($target->fresh()->consequences)->toBe(['block_new_orders'])
        ->and(DB::table('kyc_submissions')->count())->toBe($before);
});
