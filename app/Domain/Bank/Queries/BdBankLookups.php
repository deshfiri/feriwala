<?php

namespace App\Domain\Bank\Queries;

use App\Domain\Bank\Models\BdBank;
use App\Domain\Bank\Models\BdBankBranch;
use App\Domain\Bank\Models\BdBankImport;
use App\Http\Controllers\LocationLookupController;
use Illuminate\Support\Facades\Cache;

/**
 * Cached lookups behind the bank payout-method form's Bank -> District ->
 * Branch cascade (§39: never send the whole directory on every page).
 * District selection itself reuses the existing Bangladesh location
 * directory's own cascade ({@see LocationLookupController});
 * this only resolves branches once both a bank and a district are chosen.
 *
 * Cached under the latest completed import's id, so a re-import invalidates
 * every cached key automatically.
 */
class BdBankLookups
{
    /**
     * Every selectable bank — payable, identified, and not deactivated by a
     * later import.
     *
     * @return list<array{bank_code: string, name: string, slug: string}>
     */
    public function selectableBanks(): array
    {
        return Cache::remember(
            $this->cacheKey('banks', 'all'),
            now()->addDay(),
            fn () => array_values(
                BdBank::query()
                    ->where('is_active', true)
                    ->where('payable', true)
                    ->where('available_in_selector', true)
                    ->orderBy('name')
                    ->get()
                    ->map(fn (BdBank $bank) => [
                        'bank_code' => $bank->bank_code,
                        'name' => $bank->name,
                        'slug' => $bank->slug,
                    ])
                    ->all(),
            ),
        );
    }

    /**
     * A bank's active branches within one district — an empty list when the
     * bank or the district does not resolve.
     *
     * `routing_number` is the branch's stable, public-facing identifier
     * (globally unique, never a database id — the same convention `bd_banks`
     * uses `bank_code` for).
     *
     * @return list<array{routing_number: string, name: string, branch_code: string|null}>
     */
    public function branchesFor(string $bankCode, int $districtLocationId): array
    {
        return Cache::remember(
            $this->cacheKey('branches', "{$bankCode}.{$districtLocationId}"),
            now()->addDay(),
            function () use ($bankCode, $districtLocationId) {
                $bank = BdBank::query()->where('bank_code', $bankCode)->where('is_active', true)->first();

                if ($bank === null) {
                    return [];
                }

                return array_values(
                    BdBankBranch::query()
                        ->where('bank_id', $bank->id)
                        ->where('district_location_id', $districtLocationId)
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->get()
                        ->map(fn (BdBankBranch $branch) => [
                            'routing_number' => $branch->routing_number,
                            'name' => $branch->name,
                            'branch_code' => $branch->branch_code,
                        ])
                        ->all(),
                );
            },
        );
    }

    protected function cacheKey(string $scope, string $key): string
    {
        return sprintf('bd_banks.v%d.%s.%s', BdBankImport::currentVersion(), $scope, $key);
    }
}
