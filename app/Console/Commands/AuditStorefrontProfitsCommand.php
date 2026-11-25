<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Actions\OpenPartnerStockCostLots;
use App\Domain\Order\Actions\AuditStorefrontProfits;
use App\Support\Money\Money;
use Illuminate\Console\Command;

/**
 * The historical audit of Partner Storefront profit.
 *
 * Read-only by default: it reports, for every Storefront order ever delivered,
 * what the Partner earns and what would happen to it — credited after its hold,
 * already credited, waiting, blocked, reported only (their own gateway), or
 * needing review — and writes nothing at all.
 *
 * `--apply` commits that classification (and, with `--open-lots`, first gives
 * Partner stock bought before cost lots its lots). It never credits a Wallet:
 * the minute sweep credits what is due under each order's own key, and a profit
 * whose cost cannot be proven waits for finance however it is run.
 */
class AuditStorefrontProfitsCommand extends Command
{
    protected $signature = 'storefront-profit:audit
        {--apply : Record the classification (never credits anything itself)}
        {--open-lots : Also give Partner stock bought before cost lots its lots (with --apply only)}
        {--account= : Only this business account id}
        {--details : List every order, not only the summary}';

    protected $description = 'Report what Partners earn on past Storefront orders (read-only unless --apply)';

    public function handle(AuditStorefrontProfits $audit, OpenPartnerStockCostLots $lots): int
    {
        $apply = (bool) $this->option('apply');
        $account = $this->option('account') === null ? null : (int) $this->option('account');

        $lotReport = $lots->handle($apply && (bool) $this->option('open-lots'));

        $this->components->twoColumnDetail('<fg=gray>Cost lots</>', $apply && $this->option('open-lots') ? 'opened' : 'would open (not written)');
        foreach ($lotReport as $key => $value) {
            $this->components->twoColumnDetail('  '.$key, (string) $value);
        }

        $rows = $audit->handle($apply, $account);

        if ($this->option('details')) {
            $this->table(
                ['Order', 'Account', 'Status', 'Reason', 'Selling', 'Cost', 'Profit', 'Legacy credited', 'Releases', 'Cost basis', 'Error'],
                $rows->map(fn (array $row) => array_values($row))->all(),
            );
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Orders examined</>', (string) $rows->count());

        foreach ($rows->groupBy('status') as $status => $group) {
            $sum = $group->reduce(
                fn (Money $carry, array $row) => $row['profit'] === null ? $carry : $carry->plus(Money::fromDecimal($row['profit'])),
                Money::zero(),
            );
            $this->components->twoColumnDetail('  '.$status, $group->count().' order(s), profit '.$sum->toDecimal());
        }

        $this->newLine();
        $this->components->info($apply
            ? 'Classification recorded. Nothing was credited here: due profits are credited by the minute sweep; review_required ones never automatically.'
            : 'Read-only: nothing was written, nothing was credited.');

        return $rows->contains(fn (array $row) => $row['status'] === 'error') ? self::FAILURE : self::SUCCESS;
    }
}
