<?php

namespace App\Domain\Location\Actions;

use App\Domain\Location\Data\BdLocationNode;
use App\Domain\Location\Data\ImportReport;
use App\Domain\Location\Enums\BdLocationType;
use App\Domain\Location\Models\BdLocation;
use App\Domain\Location\Models\BdLocationImport;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Validate, dry-run or apply an import of the bundled Bangladesh location
 * directory (see database/data/bangladesh-location/NOTICE.md).
 *
 * The upstream JSON is not a nested tree: each level is its own flat
 * collection, children keyed by their parent's own id. IDs are confirmed
 * unique only within their level (not globally across levels), and — found
 * only by inspecting the actual files — the English file's ids are JSON
 * numbers while the Bangla file's are JSON strings, so every id is cast to a
 * string before anything is compared. The natural key a node is matched and
 * upserted against is `type` + `source_id` alone (source_id must itself be
 * unique within a type for parent references to resolve at all — a repeated
 * source_id under two different parents is rejected as a duplicate, not
 * silently allowed); `source_parent_id` is still checked for agreement
 * between languages and used to resolve `parent_id`.
 *
 * `validate` only inspects the bundled files. `dry-run` runs the exact same
 * upsert logic inside a transaction that is always rolled back. `import`
 * commits it and appends one row to `bd_location_imports`.
 */
class ImportBdLocations
{
    private const SOURCE_URL = 'https://github.com/sohan-99/bangladesh-location-data';

    private const COMMIT_SHA = '95b646aa863396eaeca81a25723cc814f24aa0c5';

    private const CHECKSUM_EN = '8a2767f18d8a56b7ef90767ad2bd5c5f8a5401eae12911995aaafd37c9b173c3';

    private const CHECKSUM_BN = '93bafc4c5adbe8f6c8e5f71dbab94d6de766e257a6bd8e9f1e3399d881287f58';

    private const LICENSE = 'MIT';

    public function __construct(protected DatabaseManager $database) {}

    /**
     * @param  'validate'|'dry-run'|'import'  $mode
     */
    public function handle(string $mode): ImportReport
    {
        return $this->handleDecoded($mode, $this->loadJson('en.json'), $this->loadJson('bn.json'));
    }

    /**
     * The same logic `handle()` uses, taking already-decoded files instead of
     * reading the bundled ones — what a test exercises to prove duplicate,
     * orphan and hierarchy-mismatch rejection without needing to corrupt the
     * real bundled data to do it.
     *
     * @param  'validate'|'dry-run'|'import'  $mode
     * @param  array<string, mixed>  $enDecoded
     * @param  array<string, mixed>  $bnDecoded
     */
    public function handleDecoded(string $mode, array $enDecoded, array $bnDecoded): ImportReport
    {
        $problems = [];
        $enByKey = $this->dedupeOrReport($this->flatten($enDecoded, 'en'), 'en', $problems);
        $bnByKey = $this->dedupeOrReport($this->flatten($bnDecoded, 'bn'), 'bn', $problems);

        $this->reportOrphans($enByKey, 'en', $problems);
        $this->reportOrphans($bnByKey, 'bn', $problems);
        $this->reportHierarchyAgreement($enByKey, $bnByKey, $problems);

        if ($problems !== [] || $mode === 'validate') {
            return new ImportReport($mode, $problems, []);
        }

        $merged = $this->merge($enByKey, $bnByKey);

        if ($mode === 'dry-run') {
            return new ImportReport($mode, [], $this->dryRun($merged));
        }

        $counts = $this->database->transaction(function () use ($merged) {
            $counts = $this->upsert($merged);

            BdLocationImport::create([
                'source_url' => self::SOURCE_URL,
                'commit_sha' => self::COMMIT_SHA,
                'checksum_en' => self::CHECKSUM_EN,
                'checksum_bn' => self::CHECKSUM_BN,
                'license' => self::LICENSE,
                'mode' => 'import',
                'divisions_count' => $counts['division'],
                'districts_count' => $counts['district'],
                'upazilas_count' => $counts['upazila'],
                'unions_count' => $counts['union'],
                'deactivated_count' => $counts['deactivated'],
                'imported_at' => now(),
            ]);

            return $counts;
        });

        return new ImportReport($mode, [], $counts);
    }

    /**
     * Runs the real upsert logic inside a transaction that is always rolled
     * back, so a dry run reports exactly what an import would do without
     * writing anything.
     *
     * @param  list<BdLocationNode>  $merged
     * @return array<string, int>
     */
    private function dryRun(array $merged): array
    {
        $counts = null;

        try {
            $this->database->transaction(function () use ($merged, &$counts): void {
                $counts = $this->upsert($merged);

                throw new DryRunRollback;
            });
        } catch (DryRunRollback) {
            // Expected — the transaction above never commits.
        }

        return $counts ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJson(string $filename): array
    {
        $path = database_path('data/bangladesh-location/'.$filename);

        if (! is_file($path)) {
            throw new RuntimeException("Bundled location file missing: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("Bundled location file is not a JSON object: {$path}");
        }

        return $decoded;
    }

    /**
     * Flattens one language file into a flat list of raw tuples. Nothing is
     * deduplicated or validated here — see {@see self::dedupeOrReport()}.
     *
     * @param  array<string, mixed>  $decoded
     * @return list<array{type: BdLocationType, sourceId: string, sourceParentId: ?string, title: string}>
     */
    private function flatten(array $decoded, string $locale): array
    {
        $rows = [];

        foreach (BdLocationType::cases() as $type) {
            $collection = $decoded[$type->sourceCollectionKey($locale)] ?? [];

            if ($type === BdLocationType::Division) {
                foreach ($collection as $item) {
                    $rows[] = [
                        'type' => $type,
                        'sourceId' => (string) $item['value'],
                        'sourceParentId' => null,
                        'title' => (string) $item['title'],
                    ];
                }

                continue;
            }

            foreach ($collection as $parentSourceId => $children) {
                foreach ($children as $item) {
                    $rows[] = [
                        'type' => $type,
                        'sourceId' => (string) $item['value'],
                        'sourceParentId' => (string) $parentSourceId,
                        'title' => (string) $item['title'],
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * Deduplicates by `"{type}|{source_id}"` — first occurrence wins, every
     * further one is appended to `$problems` rather than silently dropped.
     *
     * @param  list<array{type: BdLocationType, sourceId: string, sourceParentId: ?string, title: string}>  $raw
     * @param  list<string>  $problems
     * @return array<string, array{type: BdLocationType, sourceId: string, sourceParentId: ?string, title: string}>
     */
    private function dedupeOrReport(array $raw, string $locale, array &$problems): array
    {
        $byKey = [];
        $reported = [];

        foreach ($raw as $row) {
            $key = "{$row['type']->value}|{$row['sourceId']}";

            if (isset($byKey[$key])) {
                if (! isset($reported[$key])) {
                    $problems[] = "duplicate {$row['type']->value} source_id={$row['sourceId']} in the {$locale} file";
                    $reported[$key] = true;
                }

                continue;
            }

            $byKey[$key] = $row;
        }

        return $byKey;
    }

    /**
     * @param  array<string, array{type: BdLocationType, sourceId: string, sourceParentId: ?string, title: string}>  $byKey
     * @param  list<string>  $problems
     */
    private function reportOrphans(array $byKey, string $locale, array &$problems): void
    {
        foreach ($byKey as $key => $row) {
            if ($row['sourceParentId'] === null) {
                continue;
            }

            $parentType = $row['type']->parent();
            assert($parentType !== null, 'only a division has no source parent, and it was skipped above');

            $parentKey = "{$parentType->value}|{$row['sourceParentId']}";

            if (! isset($byKey[$parentKey])) {
                $problems[] = "orphan: {$key} in the {$locale} file references parent {$parentKey}, which does not exist";
            }
        }
    }

    /**
     * Every node must exist in both languages, under the same parent.
     *
     * @param  array<string, array{type: BdLocationType, sourceId: string, sourceParentId: ?string, title: string}>  $enByKey
     * @param  array<string, array{type: BdLocationType, sourceId: string, sourceParentId: ?string, title: string}>  $bnByKey
     * @param  list<string>  $problems
     */
    private function reportHierarchyAgreement(array $enByKey, array $bnByKey, array &$problems): void
    {
        foreach (array_diff(array_keys($enByKey), array_keys($bnByKey)) as $key) {
            $problems[] = "hierarchy mismatch: {$key} exists in the English file but has no Bangla counterpart";
        }

        foreach (array_diff(array_keys($bnByKey), array_keys($enByKey)) as $key) {
            $problems[] = "hierarchy mismatch: {$key} exists in the Bangla file but has no English counterpart";
        }

        foreach (array_intersect(array_keys($enByKey), array_keys($bnByKey)) as $key) {
            if ($enByKey[$key]['sourceParentId'] !== $bnByKey[$key]['sourceParentId']) {
                $problems[] = "hierarchy mismatch: {$key} has a different parent in the English file than in the Bangla file";
            }
        }
    }

    /**
     * @param  array<string, array{type: BdLocationType, sourceId: string, sourceParentId: ?string, title: string}>  $enByKey
     * @param  array<string, array{type: BdLocationType, sourceId: string, sourceParentId: ?string, title: string}>  $bnByKey
     * @return list<BdLocationNode>
     */
    private function merge(array $enByKey, array $bnByKey): array
    {
        $merged = [];

        // Division, then District, then Upazila, then Union — a child's
        // parent must already have been upserted (and its id resolved)
        // before the child is processed.
        foreach (BdLocationType::cases() as $type) {
            foreach ($enByKey as $key => $en) {
                if ($en['type'] !== $type) {
                    continue;
                }

                $merged[] = new BdLocationNode(
                    type: $type,
                    sourceId: $en['sourceId'],
                    sourceParentId: $en['sourceParentId'],
                    nameEn: $en['title'],
                    nameBn: $bnByKey[$key]['title'],
                );
            }
        }

        return $merged;
    }

    /**
     * @param  list<BdLocationNode>  $nodes
     * @return array<string, int>
     */
    private function upsert(array $nodes): array
    {
        $resolvedIds = [];
        $seenIds = [];
        $counts = [
            'division' => 0,
            'district' => 0,
            'upazila' => 0,
            'union' => 0,
        ];

        foreach ($nodes as $node) {
            $parentId = null;

            if ($node->sourceParentId !== null) {
                $parentType = $node->type->parent();
                assert($parentType !== null, 'only a division has no source parent');

                $parentKey = "{$parentType->value}|{$node->sourceParentId}";
                $parentId = $resolvedIds[$parentKey]
                    ?? throw new RuntimeException("Unresolved parent for {$node->type->value}|{$node->sourceId} (validation should have caught this)");
            }

            $location = BdLocation::query()->updateOrCreate(
                [
                    'type' => $node->type->value,
                    'source_id' => $node->sourceId,
                    'source_parent_id' => $node->sourceParentId,
                ],
                [
                    'parent_id' => $parentId,
                    'name_en' => $node->nameEn,
                    'name_bn' => $node->nameBn,
                    'is_active' => true,
                ],
            );

            $resolvedIds["{$node->type->value}|{$node->sourceId}"] = $location->id;
            $seenIds[] = $location->id;
            $counts[$node->type->value]++;
        }

        $counts['deactivated'] = $seenIds === []
            ? 0
            : BdLocation::query()->whereNotIn('id', $seenIds)->where('is_active', true)->update(['is_active' => false]);

        return $counts;
    }
}

/**
 * Thrown only to force {@see ImportBdLocations}'s dry-run transaction to roll
 * back after its callback has already computed the counts it needs to report.
 */
final class DryRunRollback extends RuntimeException {}
