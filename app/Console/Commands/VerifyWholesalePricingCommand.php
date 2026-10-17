<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only deployment check for the wholesale pricing method migration.
 *
 * Run `--snapshot=<file>` before migrating and `--against=<file>` after. Every
 * existing product must come out Fixed with its wholesale price, selling-price
 * bounds and its variations' prices exactly as they were; the unexpected-change
 * count must be zero. Nothing here writes to the database.
 */
class VerifyWholesalePricingCommand extends Command
{
    protected $signature = 'catalog:verify-wholesale-pricing
        {--snapshot= : Write the current prices to this file (run before migrating)}
        {--against= : Compare the current state with a snapshot (run after migrating)}';

    protected $description = 'Read-only: report that wholesale prices survived the pricing-method migration unchanged';

    /** @var list<string> */
    private const PRODUCT_FIGURES = ['wholesale_price', 'minimum_selling_price', 'maximum_selling_price', 'suggested_selling_price'];

    public function handle(): int
    {
        $snapshot = $this->option('snapshot');
        $against = $this->option('against');

        if (is_string($snapshot) && $snapshot !== '') {
            file_put_contents($snapshot, json_encode($this->capture(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $this->info("Snapshot of {$this->productCount()} products written to {$snapshot}.");

            return self::SUCCESS;
        }

        $total = $this->productCount();
        $methods = $this->methodCounts();
        $rows = [
            ['Total existing products', $total],
            ['Products marked Fixed', $methods['fixed'] ?? 0],
            ['Products marked Percentage', $methods['percentage'] ?? 0],
        ];

        if (! is_string($against) || $against === '' || ! is_file($against)) {
            $this->table(['Check', 'Count'], $rows);
            $this->warn('No snapshot given: pass --against=<file> to count unexpected price changes.');

            return self::SUCCESS;
        }

        /** @var array{products: array<string, array<string, string|null>>, variants: array<string, string|null>} $before */
        $before = json_decode((string) file_get_contents($against), true, 512, JSON_THROW_ON_ERROR);
        $now = $this->capture();
        $current = DB::table('products')->pluck('wholesale_pricing_method', 'id');

        $changed = 0;
        $missing = 0;

        foreach ($before['products'] as $id => $figures) {
            $row = $now['products'][$id] ?? null;

            // A product staff deliberately moved to Percentage is allowed to differ.
            if (($current[(int) $id] ?? null) === 'percentage') {
                continue;
            }

            if ($figures['wholesale_price'] !== null && ($row === null || $row['wholesale_price'] === null)) {
                $missing++;
            } elseif ($row !== null && $row !== $figures) {
                $changed++;
            }
        }

        foreach ($before['variants'] as $id => $price) {
            if (($now['variants'][$id] ?? null) !== $price) {
                $changed++;
            }
        }

        $rows[] = ['Products whose price or selling bounds changed unexpectedly (and variations)', $changed];
        $rows[] = ['Products missing a wholesale price they previously had', $missing];
        $this->table(['Check', 'Count'], $rows);

        return $changed === 0 && $missing === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{products: array<int, array<string, string|null>>, variants: array<int, string|null>}
     */
    private function capture(): array
    {
        $products = [];

        foreach (DB::table('products')->get(['id', ...self::PRODUCT_FIGURES]) as $row) {
            $products[(int) $row->id] = collect(self::PRODUCT_FIGURES)
                ->mapWithKeys(fn (string $figure) => [$figure => $row->{$figure} === null ? null : (string) $row->{$figure}])
                ->all();
        }

        $variants = DB::table('product_variants')->pluck('wholesale_price', 'id')
            ->map(fn (mixed $price) => $price === null ? null : (string) $price)
            ->all();

        return ['products' => $products, 'variants' => $variants];
    }

    private function productCount(): int
    {
        return DB::table('products')->count();
    }

    /**
     * @return array<string, int>
     */
    private function methodCounts(): array
    {
        return DB::table('products')
            ->selectRaw('wholesale_pricing_method as method, count(*) as total')
            ->groupBy('wholesale_pricing_method')
            ->pluck('total', 'method')
            ->map(fn (mixed $count) => (int) $count)
            ->all();
    }
}
