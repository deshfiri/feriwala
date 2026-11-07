<?php

namespace App\Console\Commands;

use App\Domain\Account\Models\BusinessAccount;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Gives every partner who joined before partner numbers existed their own.
 *
 * Safe to run again at any time: it only ever fills a number that is missing, in a
 * single statement that re-checks the column, so an existing number is never
 * replaced and two runs at once cannot both assign. Closed (soft-deleted)
 * accounts get one too, so the register has no gaps. It does not touch
 * `updated_at`: a number being filled in is not a change the partner made.
 */
class AssignPartnerNumbersCommand extends Command
{
    protected $signature = 'partners:assign-numbers
        {--dry-run : Count the partners that would get a number, and change nothing}';

    protected $description = 'Give every partner account that has no BJ number one (existing numbers are never changed)';

    private const COLLISION_ATTEMPTS = 3;

    public function handle(): int
    {
        $missing = BusinessAccount::withTrashed()->whereNull('partner_number')->count();

        if ($missing === 0) {
            $this->components->info('Every partner already has a number.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->info("{$missing} partner(s) would get a number. Nothing was changed.");

            return self::SUCCESS;
        }

        $assigned = 0;

        BusinessAccount::withTrashed()
            ->whereNull('partner_number')
            ->chunkById(200, function ($accounts) use (&$assigned): void {
                foreach ($accounts as $account) {
                    $assigned += $this->assign($account);
                }
            });

        $this->components->info("Gave {$assigned} of {$missing} partner(s) a number.");

        return $assigned === $missing ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return int 1 when the partner got a number, 0 when they already had one or none could be placed
     */
    private function assign(BusinessAccount $account): int
    {
        for ($attempt = 0; $attempt < self::COLLISION_ATTEMPTS; $attempt++) {
            try {
                return DB::table('business_accounts')
                    ->where('id', $account->getKey())
                    ->whereNull('partner_number')
                    ->update(['partner_number' => $account->generatePartnerNumber()]);
            } catch (UniqueConstraintViolationException) {
                // Another number took this one between the check and the write; draw again.
            }
        }

        $this->components->warn("Could not place a number for partner {$account->public_id}; run the command again.");

        return 0;
    }
}
