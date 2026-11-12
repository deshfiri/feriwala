<?php

namespace App\Domain\Bank\Queries;

use App\Domain\Bank\Models\BdBank;
use App\Domain\Bank\Models\BdBankBranch;
use App\Domain\Bank\Models\BdBankImport;
use App\Http\Controllers\LocationLookupController;
use Illuminate\Database\Eloquent\Builder;
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
     * @return list<array{id: int, bank_code: string, name: string, slug: string, aliases: list<string>, branch_count: int}>
     */
    public function selectableBanks(): array
    {
        return Cache::remember(
            $this->cacheKey('banks', 'all.v2'),
            now()->addDay(),
            fn () => array_values(
                $this->selectable()
                    ->get()
                    ->map(fn (BdBank $bank) => $this->bankRow($bank))
                    ->all(),
            ),
        );
    }

    /**
     * Every selectable bank with all of its active branches in one answer, for
     * an app that keeps the whole directory on the device.
     *
     * @return list<array<string, mixed>>
     */
    public function banksWithBranches(): array
    {
        return Cache::remember(
            $this->cacheKey('banks', 'with-branches'),
            now()->addDay(),
            function () {
                $banks = $this->selectable()->get();

                $branches = BdBankBranch::query()
                    ->with('district:id,name_en,name_bn')
                    ->whereIn('bank_id', $banks->pluck('id'))
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get()
                    ->groupBy('bank_id');

                return array_values($banks->map(fn (BdBank $bank) => $this->bankRow($bank) + [
                    'branches' => array_values(($branches->get($bank->id) ?? collect())
                        ->map(fn (BdBankBranch $branch) => $this->branchRow($branch, $bank->bank_code))
                        ->all()),
                ])->all());
            },
        );
    }

    /**
     * A bank's active branches — within one district when one is given, all of
     * them otherwise. An empty list when the bank does not resolve.
     *
     * `routing_number` is the branch's stable, public-facing identifier
     * (globally unique — the same convention `bd_banks` uses `bank_code` for);
     * `id` is the directory's own row id, and `district_id` the location
     * directory's id for the branch's district.
     *
     * @return list<array<string, mixed>>
     */
    public function branchesFor(string $bankCode, ?int $districtLocationId = null): array
    {
        return Cache::remember(
            $this->cacheKey('branches', "{$bankCode}.".($districtLocationId ?? 'all').'.v2'),
            now()->addDay(),
            function () use ($bankCode, $districtLocationId) {
                $bank = BdBank::query()->where('bank_code', $bankCode)->where('is_active', true)->first();

                if ($bank === null) {
                    return [];
                }

                return array_values(
                    BdBankBranch::query()
                        ->with('district:id,name_en,name_bn')
                        ->where('bank_id', $bank->id)
                        ->when($districtLocationId !== null, fn ($query) => $query->where('district_location_id', $districtLocationId))
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->get()
                        ->map(fn (BdBankBranch $branch) => $this->branchRow($branch, $bank->bank_code))
                        ->all(),
                );
            },
        );
    }

    /**
     * @return Builder<BdBank>
     */
    protected function selectable(): Builder
    {
        return BdBank::query()
            ->where('is_active', true)
            ->where('payable', true)
            ->where('available_in_selector', true)
            ->orderBy('name');
    }

    /**
     * @return array{id: int, bank_code: string, name: string, slug: string, aliases: list<string>, branch_count: int}
     */
    protected function bankRow(BdBank $bank): array
    {
        return [
            'id' => $bank->id,
            'bank_code' => $bank->bank_code,
            'name' => $bank->name,
            'slug' => $bank->slug,
            'aliases' => array_values($bank->aliases ?? []),
            'branch_count' => $bank->branch_count,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function branchRow(BdBankBranch $branch, string $bankCode): array
    {
        return [
            'id' => $branch->id,
            'bank_code' => $bankCode,
            'routing_number' => $branch->routing_number,
            'name' => $branch->name,
            'slug' => $branch->slug,
            'branch_code' => $branch->branch_code,
            'original_branch_code' => $branch->original_branch_code,
            'district_id' => $branch->district_location_id,
            'district_name_en' => $branch->district?->name_en,
            'district_name_bn' => $branch->district?->name_bn,
            'district_source_name' => $branch->district_source_name,
            'swift_code' => $branch->swift_code,
            'address' => $branch->address,
            'telephone' => $branch->telephone,
            'email' => $branch->email,
            'fax' => $branch->fax,
        ];
    }

    protected function cacheKey(string $scope, string $key): string
    {
        return sprintf('bd_banks.v%d.%s.%s', BdBankImport::currentVersion(), $scope, $key);
    }
}
