<?php

namespace App\Domain\Bank\Actions;

use App\Domain\Bank\Data\BdBankImportReport;
use App\Domain\Bank\DistrictAliases;
use App\Domain\Bank\Models\BdBank;
use App\Domain\Bank\Models\BdBankBranch;
use App\Domain\Bank\Models\BdBankImport;
use App\Domain\Location\Enums\BdLocationType;
use App\Domain\Location\Models\BdLocation;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Validate, dry-run or apply an import of the bundled Bangladesh bank and
 * branch directory (see database/data/bangladesh-bank/NOTICE.md).
 *
 * `bangladesh_bank_branches_flat.json` is canonical for branch rows;
 * `bangladesh_bank_branches_website.json`'s top-level `banks[]` is read too,
 * but only for the bank-level fields (`payable`, `aliases`,
 * `available_in_selector`, `reference`, `branch_count`) that never appear on
 * a flat branch row, and for the handful of institutions with zero branches
 * that therefore never appear in `_flat.json` at all. Its nested
 * `districts[].branches[]` are not read again — confirmed identical to
 * `_flat.json` (see NOTICE.md). `bangladesh_bank_branches_audit.json` is read
 * only for its issue count, which is reported, never used to populate a
 * table.
 *
 * `validate` only inspects the bundled files. `dry-run` runs the exact same
 * upsert logic inside a transaction that is always rolled back. `import`
 * commits it and appends one row to `bd_bank_imports`.
 */
class ImportBdBanks
{
    public function __construct(protected DatabaseManager $database) {}

    /**
     * @param  'validate'|'dry-run'|'import'  $mode
     */
    public function handle(string $mode): BdBankImportReport
    {
        return $this->handleDecoded(
            $mode,
            $this->loadJson('bangladesh_bank_branches_website.json'),
            $this->loadJson('bangladesh_bank_branches_flat.json'),
            $this->loadJson('bangladesh_bank_branches_audit.json'),
        );
    }

    /**
     * The same logic {@see handle()} uses, taking already-decoded files
     * instead of reading the bundled ones — what a test exercises to prove
     * duplicate, orphan and alias-resolution behaviour without needing 8,649
     * real rows to do it.
     *
     * @param  'validate'|'dry-run'|'import'  $mode
     * @param  array<string, mixed>  $website
     * @param  array<string, mixed>  $flat
     * @param  array<string, mixed>  $audit
     */
    public function handleDecoded(string $mode, array $website, array $flat, array $audit): BdBankImportReport
    {
        $problems = [];
        $this->checkMetaAgreement($website, $flat, $audit, $problems);

        $banks = $this->parseBanks($website, $problems);
        $bankCodes = array_fill_keys(array_map(static fn (array $b): string => $b['bank_code'], $banks), true);

        $districtsByNormalizedName = $this->districtLookup();
        [$branches, $unmatchedDistricts] = $this->parseBranches($flat, $bankCodes, $districtsByNormalizedName, $problems);

        $excludedBySourceCount = count($audit['issues'] ?? []);

        if ($problems !== [] || $mode === 'validate') {
            return new BdBankImportReport($mode, $problems, [], array_values($unmatchedDistricts));
        }

        if ($mode === 'dry-run') {
            $counts = $this->dryRun($banks, $branches);

            return new BdBankImportReport($mode, [], $counts, array_values($unmatchedDistricts));
        }

        $counts = $this->database->transaction(function () use ($banks, $branches, $flat, $unmatchedDistricts, $excludedBySourceCount) {
            $counts = $this->upsert($banks, $branches);

            BdBankImport::create([
                'checksum_flat' => hash('sha256', (string) file_get_contents($this->dataPath('bangladesh_bank_branches_flat.json'))),
                'checksum_website' => hash('sha256', (string) file_get_contents($this->dataPath('bangladesh_bank_branches_website.json'))),
                'checksum_audit' => hash('sha256', (string) file_get_contents($this->dataPath('bangladesh_bank_branches_audit.json'))),
                'source_generated_utc' => (string) ($flat['meta']['generated_utc'] ?? ''),
                'coverage' => (string) ($flat['meta']['coverage'] ?? ''),
                'mode' => 'import',
                'banks_count' => $counts['banks'],
                'branches_count' => $counts['branches'],
                'deactivated_banks_count' => $counts['deactivated_banks'],
                'deactivated_branches_count' => $counts['deactivated_branches'],
                'unmatched_district_count' => count($unmatchedDistricts),
                'excluded_by_source_count' => $excludedBySourceCount,
                'imported_at' => now(),
            ]);

            return $counts;
        });

        return new BdBankImportReport($mode, [], $counts, array_values($unmatchedDistricts));
    }

    /**
     * @param  array<string, mixed>  $website
     * @param  array<string, mixed>  $flat
     * @param  array<string, mixed>  $audit
     * @param  list<string>  $problems
     */
    private function checkMetaAgreement(array $website, array $flat, array $audit, array &$problems): void
    {
        $generated = [
            'website' => $website['meta']['generated_utc'] ?? null,
            'flat' => $flat['meta']['generated_utc'] ?? null,
            'audit' => $audit['meta']['generated_utc'] ?? null,
        ];

        if (count(array_unique($generated)) > 1) {
            $problems[] = 'meta.generated_utc disagrees between the three bundled files ('.
                implode(', ', array_map(static fn (string $k, $v): string => "{$k}={$v}", array_keys($generated), $generated)).
                ') — refusing to import a mismatched batch.';
        }
    }

    /**
     * @param  array<string, mixed>  $website
     * @param  list<string>  $problems
     * @return list<array{bank_code: string, name: string, slug: string, aliases: list<string>, payable: ?bool, available_in_selector: bool, branch_count: int}>
     */
    private function parseBanks(array $website, array &$problems): array
    {
        $banks = [];
        $seenCodes = [];

        foreach (($website['banks'] ?? []) as $raw) {
            $code = (string) ($raw['bank_code'] ?? '');

            if (! preg_match('/^\d{3}$/', $code)) {
                $problems[] = "bank has an invalid bank_code: '{$code}'";

                continue;
            }

            if (isset($seenCodes[$code])) {
                $problems[] = "duplicate bank_code={$code} in the website file";

                continue;
            }

            $seenCodes[$code] = true;

            $name = trim((string) ($raw['name'] ?? ''));

            if ($name === '') {
                $problems[] = "bank_code={$code} has no name";

                continue;
            }

            $banks[] = [
                'bank_code' => $code,
                'name' => $name,
                'slug' => (string) ($raw['slug'] ?? $code),
                'aliases' => array_values(array_map('strval', $raw['aliases'] ?? [])),
                'payable' => $raw['payable'] === null ? null : (bool) $raw['payable'],
                'available_in_selector' => (bool) ($raw['available_in_selector'] ?? false),
                'branch_count' => (int) ($raw['branch_count'] ?? 0),
            ];
        }

        return $banks;
    }

    /**
     * `bd_locations` district names, normalized, mapped to their id — the
     * table a branch's district text is matched against.
     *
     * @return array<string, int>
     */
    private function districtLookup(): array
    {
        return BdLocation::query()
            ->ofType(BdLocationType::District)
            ->where('is_active', true)
            ->pluck('id', 'name_en')
            ->mapWithKeys(fn (int $id, string $name): array => [DistrictAliases::normalize($name) => $id])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $flat
     * @param  array<string, true>  $bankCodes
     * @param  array<string, int>  $districtsByNormalizedName
     * @param  list<string>  $problems
     * @return array{0: list<array{bank_code: string, routing_number: string, name: string, slug: string, district_source_name: string, district_location_id: ?int, branch_code: ?string, original_branch_code: ?string, swift_code: ?string, address: ?string, telephone: ?string, email: ?string, fax: ?string, source: string, source_status: string}>, 1: array<string, string>}
     */
    private function parseBranches(array $flat, array $bankCodes, array $districtsByNormalizedName, array &$problems): array
    {
        $branches = [];
        $seenRouting = [];
        $unmatchedDistricts = [];

        foreach (($flat['branches'] ?? []) as $raw) {
            $routing = (string) ($raw['routing_number'] ?? '');
            $bankCode = (string) ($raw['bank_code'] ?? '');

            if (! preg_match('/^\d{9}$/', $routing)) {
                $problems[] = "invalid routing_number '{$routing}' (bank_code={$bankCode})";

                continue;
            }

            if (isset($seenRouting[$routing])) {
                $problems[] = "duplicate routing_number={$routing} in the flat file";

                continue;
            }

            $seenRouting[$routing] = true;

            if (substr($routing, 0, 3) !== $bankCode) {
                $problems[] = "routing_number={$routing} does not start with its own bank_code={$bankCode}";

                continue;
            }

            if (! isset($bankCodes[$bankCode])) {
                $problems[] = "routing_number={$routing} references bank_code={$bankCode}, which is not in the bank list";

                continue;
            }

            $districtRaw = trim((string) ($raw['district'] ?? ''));
            $districtLocationId = null;

            if ($districtRaw !== '') {
                $resolved = DistrictAliases::resolve($districtRaw);
                $districtLocationId = $districtsByNormalizedName[$resolved] ?? null;

                if ($districtLocationId === null) {
                    $unmatchedDistricts[$districtRaw] = $districtRaw;
                }
            }

            $branches[] = [
                'bank_code' => $bankCode,
                'routing_number' => $routing,
                'name' => (string) ($raw['name'] ?? ''),
                'slug' => (string) ($raw['slug'] ?? $routing),
                'district_source_name' => $districtRaw,
                'district_location_id' => $districtLocationId,
                'branch_code' => $this->nullableString($raw['branch_code'] ?? null),
                'original_branch_code' => $this->nullableString($raw['original_branch_code'] ?? null),
                'swift_code' => $this->nullableString($raw['swift_code'] ?? null),
                'address' => $this->nullableString($raw['address'] ?? null),
                'telephone' => $this->nullableString($raw['telephone'] ?? null),
                'email' => $this->nullableString($raw['email'] ?? null),
                'fax' => $this->nullableString($raw['fax'] ?? null),
                'source' => (string) ($raw['source'] ?? 'uploaded_file'),
                'source_status' => (string) ($raw['status'] ?? 'legacy_unverified'),
            ];
        }

        return [$branches, $unmatchedDistricts];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  list<array<string, mixed>>  $banks
     * @param  list<array<string, mixed>>  $branches
     * @return array<string, int>
     */
    private function dryRun(array $banks, array $branches): array
    {
        $counts = null;

        try {
            $this->database->transaction(function () use ($banks, $branches, &$counts): void {
                $counts = $this->upsert($banks, $branches);

                throw new BdBankDryRunRollback;
            });
        } catch (BdBankDryRunRollback) {
            // Expected — the transaction above never commits.
        }

        return $counts ?? [];
    }

    /**
     * @param  list<array<string, mixed>>  $banks
     * @param  list<array<string, mixed>>  $branches
     * @return array<string, int>
     */
    private function upsert(array $banks, array $branches): array
    {
        $bankIdsByCode = [];
        $seenBankIds = [];

        foreach ($banks as $bank) {
            $model = BdBank::query()->updateOrCreate(
                ['bank_code' => $bank['bank_code']],
                [
                    'name' => $bank['name'],
                    'slug' => $bank['slug'],
                    'aliases' => $bank['aliases'],
                    'payable' => $bank['payable'],
                    'available_in_selector' => $bank['available_in_selector'],
                    'branch_count' => $bank['branch_count'],
                    'is_active' => true,
                ],
            );

            $bankIdsByCode[$bank['bank_code']] = $model->id;
            $seenBankIds[] = $model->id;
        }

        $seenBranchIds = [];

        foreach ($branches as $branch) {
            $model = BdBankBranch::query()->updateOrCreate(
                ['routing_number' => $branch['routing_number']],
                [
                    'bank_id' => $bankIdsByCode[$branch['bank_code']],
                    'name' => $branch['name'],
                    'slug' => $branch['slug'],
                    'district_location_id' => $branch['district_location_id'],
                    'district_source_name' => $branch['district_source_name'],
                    'branch_code' => $branch['branch_code'],
                    'original_branch_code' => $branch['original_branch_code'],
                    'swift_code' => $branch['swift_code'],
                    'address' => $branch['address'],
                    'telephone' => $branch['telephone'],
                    'email' => $branch['email'],
                    'fax' => $branch['fax'],
                    'source' => $branch['source'],
                    'source_status' => $branch['source_status'],
                    'is_active' => true,
                ],
            );

            $seenBranchIds[] = $model->id;
        }

        $deactivatedBanks = $seenBankIds === [] ? 0 : BdBank::query()
            ->whereNotIn('id', $seenBankIds)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $deactivatedBranches = $seenBranchIds === [] ? 0 : BdBankBranch::query()
            ->whereNotIn('id', $seenBranchIds)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        return [
            'banks' => count($banks),
            'branches' => count($branches),
            'deactivated_banks' => $deactivatedBanks,
            'deactivated_branches' => $deactivatedBranches,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJson(string $filename): array
    {
        $path = $this->dataPath($filename);

        if (! is_file($path)) {
            throw new RuntimeException("Bundled bank data file missing: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("Bundled bank data file is not a JSON object: {$path}");
        }

        return $decoded;
    }

    private function dataPath(string $filename): string
    {
        return database_path('data/bangladesh-bank/'.$filename);
    }
}

/**
 * Thrown only to force {@see ImportBdBanks}'s dry-run transaction to roll
 * back after its callback has already computed the counts it needs to
 * report.
 */
final class BdBankDryRunRollback extends RuntimeException {}
