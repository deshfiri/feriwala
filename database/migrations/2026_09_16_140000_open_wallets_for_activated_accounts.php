<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A wallet for every account that was activated before wallets existed (§23).
 *
 * §23 opens with "Every Active Account will have a Wallet and Financial Ledger",
 * and P2-1 makes that true from the activation onwards. It says nothing about
 * the accounts that were already trading when the table was created: those have
 * no wallet, and nothing in the application will ever give them one, because a
 * wallet opens with an activation that has already happened. Their wallet page
 * would answer 404 forever.
 *
 * So they are given one here, empty, exactly as activation would have. Nothing
 * is credited and no entry is written — every figure in a wallet has to be
 * explained by the ledger, and an opening balance would be one that is not.
 *
 * Idempotent, and it has to be: it inserts only where no wallet exists, so it
 * can be re-run and so an account activated between deploy and migrate is not
 * given a second one. The unique index on `business_account_id` is the backstop.
 *
 * The statuses are written out rather than read from the enum on purpose. A
 * migration is a historical record of what was done on the day; reading today's
 * enum would make what this did in the past change as the enum does.
 */
return new class extends Migration
{
    /**
     * The statuses that mean "this account has completed activation".
     *
     * A copy of `AccountStatus::isActivated()` as it stood. Accounts outside
     * this list get their wallet from the activation itself, which is the only
     * thing that should ever open one.
     *
     * @var array<int, string>
     */
    protected const ACTIVATED = [
        'active',
        'package_renewal_due',
        'package_expired',
        'low_wallet_balance',
        'wallet_topup_required',
        'temporarily_restricted',
    ];

    public function up(): void
    {
        $now = now();

        DB::table('business_accounts')
            ->whereIn('status', self::ACTIVATED)
            ->whereNotExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('wallets')
                ->whereColumn('wallets.business_account_id', 'business_accounts.id'))
            ->orderBy('id')
            ->chunkById(200, function ($accounts) use ($now) {
                $rows = [];

                foreach ($accounts as $account) {
                    $rows[] = [
                        'public_id' => (string) Str::ulid(),
                        'business_account_id' => $account->id,

                        // BDT is the base and operational currency (D4).
                        'currency_code' => 'BDT',
                        'total_minor' => 0,
                        'required_deposit_minor' => 0,
                        'reserved_minor' => 0,
                        'pending_minor' => 0,
                        'hold_minor' => 0,
                        'cod_receivable_minor' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('wallets')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        /*
         * Nothing. Deleting wallets would delete the ledger entries hanging off
         * them, and a rollback is not a reason to destroy financial records —
         * an empty wallet costs a row and explains itself.
         */
    }
};
