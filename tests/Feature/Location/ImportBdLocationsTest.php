<?php

use App\Domain\Location\Actions\ImportBdLocations;
use App\Domain\Location\Enums\BdLocationType;
use App\Domain\Location\Models\BdLocation;
use App\Domain\Location\Models\BdLocationImport;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A minimal, valid four-level dataset — one division, one district, one
 * upazila, one union — small enough to assert exact counts against, unlike
 * the real bundled files (~5,000 rows).
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function locationTestFixture(bool $includeUnion = true): array
{
    $en = [
        'divisions_en' => [['value' => 1, 'title' => 'Dhaka']],
        'districts_en' => ['1' => [['value' => 10, 'title' => 'Dhaka District']]],
        'upazilas_en' => ['10' => [['value' => 100, 'title' => 'Savar']]],
        'unions_en' => $includeUnion ? ['100' => [['value' => 1000, 'title' => 'Savar Union']]] : [],
    ];

    $bn = [
        'divisions_bn' => [['value' => '1', 'title' => 'ঢাকা']],
        'districts_bn' => ['1' => [['value' => '10', 'title' => 'ঢাকা জেলা']]],
        'upazilas_bn' => ['10' => [['value' => '100', 'title' => 'সাভার']]],
        'unions_bn' => $includeUnion ? ['100' => [['value' => '1000', 'title' => 'সাভার ইউনিয়ন']]] : [],
    ];

    return [$en, $bn];
}

it('validates the bundled location data with no problems', function () {
    $report = app(ImportBdLocations::class)->handle('validate');

    expect($report->isValid())->toBeTrue()
        ->and($report->problems)->toBe([]);
});

it('imports the bundled location data', function () {
    $report = app(ImportBdLocations::class)->handle('import');

    expect($report->isValid())->toBeTrue()
        ->and($report->counts['division'])->toBeGreaterThan(0)
        ->and($report->counts['district'])->toBeGreaterThan(0)
        ->and($report->counts['upazila'])->toBeGreaterThan(0)
        ->and($report->counts['union'])->toBeGreaterThan(0);

    expect(BdLocationImport::query()->count())->toBe(1);

    $dhaka = BdLocation::query()->ofType(BdLocationType::Division)
        ->where('name_en', 'Dhaka')->first();
    expect($dhaka)->not->toBeNull();

    $savar = BdLocation::query()->ofType(BdLocationType::Upazila)
        ->where('name_en', 'Savar')->first();
    expect($savar)->not->toBeNull()
        ->and($savar->name_bn)->not->toBe('');
});

it('does not write anything in dry-run mode', function () {
    [$en, $bn] = locationTestFixture();

    $report = app(ImportBdLocations::class)->handleDecoded('dry-run', $en, $bn);

    expect($report->isValid())->toBeTrue()
        ->and($report->counts)->toBe([
            'division' => 1,
            'district' => 1,
            'upazila' => 1,
            'union' => 1,
            'deactivated' => 0,
        ]);

    expect(BdLocation::query()->count())->toBe(0)
        ->and(BdLocationImport::query()->count())->toBe(0);
});

it('is idempotent — importing the same data twice writes the same rows once', function () {
    [$en, $bn] = locationTestFixture();
    $importer = app(ImportBdLocations::class);

    $first = $importer->handleDecoded('import', $en, $bn);
    $second = $importer->handleDecoded('import', $en, $bn);

    expect($first->counts)->toBe($second->counts)
        ->and(BdLocation::query()->count())->toBe(4)
        ->and(BdLocationImport::query()->count())->toBe(2);
});

it('deactivates a location missing from a later import instead of deleting it', function () {
    [$en, $bn] = locationTestFixture(includeUnion: true);
    $importer = app(ImportBdLocations::class);

    $importer->handleDecoded('import', $en, $bn);

    $union = BdLocation::query()->ofType(BdLocationType::Union)->firstOrFail();
    expect($union->is_active)->toBeTrue();

    [$enWithoutUnion, $bnWithoutUnion] = locationTestFixture(includeUnion: false);
    $report = $importer->handleDecoded('import', $enWithoutUnion, $bnWithoutUnion);

    expect($report->counts['deactivated'])->toBe(1)
        ->and(BdLocation::query()->count())->toBe(4)
        ->and($union->refresh()->is_active)->toBeFalse();
});

it('rejects a duplicate source_id within a type', function () {
    [$en, $bn] = locationTestFixture();
    $en['districts_en']['1'][] = ['value' => 10, 'title' => 'Duplicate District'];
    $bn['districts_bn']['1'][] = ['value' => '10', 'title' => 'ডুপ্লিকেট জেলা'];

    $report = app(ImportBdLocations::class)->handleDecoded('validate', $en, $bn);

    expect($report->isValid())->toBeFalse()
        ->and(collect($report->problems)->contains(fn ($p) => str_contains($p, 'duplicate district source_id=10')))->toBeTrue();

    expect(BdLocation::query()->count())->toBe(0);
});

it('rejects an orphan whose parent does not exist', function () {
    [$en, $bn] = locationTestFixture();
    $en['upazilas_en']['999'] = [['value' => 200, 'title' => 'Orphan Upazila']];
    $bn['upazilas_bn']['999'] = [['value' => '200', 'title' => 'অনাথ উপজেলা']];

    $report = app(ImportBdLocations::class)->handleDecoded('validate', $en, $bn);

    expect($report->isValid())->toBeFalse()
        ->and(collect($report->problems)->contains(fn ($p) => str_contains($p, 'orphan: upazila|200')))->toBeTrue();
});

it('rejects a hierarchy mismatch between the English and Bangla files', function () {
    [$en, $bn] = locationTestFixture();
    // Bangla moves the union under a district-level id it never had in English.
    $bn['unions_bn'] = ['999' => [['value' => '1000', 'title' => 'সাভার ইউনিয়ন']]];

    $report = app(ImportBdLocations::class)->handleDecoded('validate', $en, $bn);

    expect($report->isValid())->toBeFalse()
        ->and(collect($report->problems)->contains(
            fn ($p) => str_contains($p, 'hierarchy mismatch') && str_contains($p, 'union|1000')
        ))->toBeTrue();
});

it('never allows bd_location_imports to be updated or deleted', function () {
    [$en, $bn] = locationTestFixture();
    app(ImportBdLocations::class)->handleDecoded('import', $en, $bn);

    $id = BdLocationImport::query()->firstOrFail()->id;

    expect(fn () => DB::table('bd_location_imports')->where('id', $id)->update(['mode' => 'validate']))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('bd_location_imports')->where('id', $id)->delete())
        ->toThrow(QueryException::class);
});

it('never deletes a bd_locations row, even directly', function () {
    [$en, $bn] = locationTestFixture();
    app(ImportBdLocations::class)->handleDecoded('import', $en, $bn);

    $id = BdLocation::query()->firstOrFail()->id;

    expect(fn () => DB::table('bd_locations')->where('id', $id)->delete())
        ->toThrow(QueryException::class);
});
