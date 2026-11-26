<?php

namespace App\Console\Commands;

use App\Domain\Order\Actions\ReconcileStorefrontHistory;
use App\Domain\Order\Data\StorefrontReconciliationOptions;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * The one legacy reconciliation for Storefront orders: account type, courier
 * consignment, delivery, collection, cost, Partner profit and label (D40).
 *
 * Read-only unless `--apply`. It never books a courier, never moves a status
 * outside the existing legal transitions, never credits a profit twice, and a
 * second `--apply` has no effect. A record a person must look at is reported,
 * not guessed; the exit code is non-zero only for technical failures.
 */
class ReconcileStorefrontHistoryCommand extends Command
{
    protected $signature = 'storefront:reconcile-history
        {--apply : Make the changes (default: a dry run that writes nothing)}
        {--order= : One order, by its reference}
        {--partner= : One Partner, by the business account public id}
        {--account= : The same as --partner}
        {--website= : One Storefront, by its public id}
        {--from= : Orders placed on or after this date (Y-m-d)}
        {--to= : Orders placed on or before this date (Y-m-d)}
        {--provider= : Only orders with a parcel at this courier (steadfast)}
        {--resume= : Continue an interrupted --apply run from its checkpoint}
        {--details : List every order, not only the summary}
        {--no-courier-api : Reconcile from the database only; ask no courier}
        {--limit= : At most this many orders}';

    protected $description = 'Reconcile historical Storefront orders: courier, delivery, collection, cost and Partner profit (dry run unless --apply)';

    /** @var list<string> */
    protected $aliases = ['storefront-profit:reconcile'];

    public function handle(ReconcileStorefrontHistory $reconcile): int
    {
        $provider = $this->option('provider');

        if ($provider !== null && $provider !== 'steadfast') {
            $this->components->error('Only --provider=steadfast is supported.');

            return self::INVALID;
        }

        $options = new StorefrontReconciliationOptions(
            apply: (bool) $this->option('apply') || $this->option('resume') !== null,
            order: $this->option('order'),
            partner: $this->option('partner') ?? $this->option('account'),
            provider: $provider,
            resume: $this->option('resume'),
            courierApi: ! $this->option('no-courier-api'),
            limit: is_numeric($this->option('limit')) ? max(1, (int) $this->option('limit')) : null,
            website: $this->option('website'),
            from: $this->date('from'),
            to: $this->date('to'),
        );

        $this->components->info($options->apply ? 'Applying the reconciliation.' : 'Dry run: nothing will be written.');

        try {
            $result = $reconcile->run($options, $this->option('details') ? fn (string $line) => $this->line($line) : fn (string $line) => $this->output->write('.'));
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine(2);

        if ($result['run'] !== null) {
            $this->components->twoColumnDetail('Run', $result['run']);
        }

        foreach ($result['counts'] as $key => $value) {
            $this->components->twoColumnDetail(str_replace('_', ' ', ucfirst($key)), (string) $value);
        }

        $this->newLine();
        $this->components->twoColumnDetail('Report (JSON)', $result['report']['json']);
        $this->components->twoColumnDetail('Report (CSV)', $result['report']['csv']);

        if (! $options->apply) {
            $this->components->info('Dry run: nothing was written. Run again with --apply to make these changes.');
        }

        return (int) $result['counts']['technical_failures'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function date(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
