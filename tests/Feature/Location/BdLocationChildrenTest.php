<?php

use App\Domain\Location\Enums\BdLocationType;
use App\Domain\Location\Models\BdLocation;
use App\Domain\Location\Models\BdLocationImport;
use App\Domain\Location\Queries\BdLocationChildren;

it('returns the bilingual children of a given parent', function () {
    $chain = addressTestLocationChain();

    $rows = app(BdLocationChildren::class)->childrenOf(BdLocationType::Division, $chain['division']->source_id);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toBe([
            'id' => $chain['district']->id,
            'source_id' => $chain['district']->source_id,
            'name_en' => 'Test District',
            'name_bn' => 'টেস্ট জেলা',
        ]);
});

it('returns an empty list for a union, which has no children', function () {
    $chain = addressTestLocationChain();

    expect(app(BdLocationChildren::class)->childrenOf(BdLocationType::Union, $chain['union']->source_id))->toBe([]);
});

it('caches the result under the current import version, and busts it on a new import', function () {
    $chain = addressTestLocationChain();
    $lookup = app(BdLocationChildren::class);

    $before = $lookup->childrenOf(BdLocationType::Division, $chain['division']->source_id);
    expect($before)->toHaveCount(1);

    // Added straight to the database, bypassing the importer — the cached
    // read must not see it yet.
    $secondDistrict = BdLocation::create([
        'type' => BdLocationType::District,
        'parent_id' => $chain['division']->id,
        'source_id' => 'second-district',
        'source_parent_id' => $chain['division']->source_id,
        'name_en' => 'Second District',
        'name_bn' => 'দ্বিতীয় জেলা',
        'is_active' => true,
    ]);

    $stillCached = $lookup->childrenOf(BdLocationType::Division, $chain['division']->source_id);
    expect($stillCached)->toHaveCount(1);

    // A new import bumps the version, which is part of the cache key.
    BdLocationImport::create([
        'source_url' => 'https://example.test',
        'commit_sha' => str_repeat('a', 40),
        'checksum_en' => str_repeat('b', 64),
        'checksum_bn' => str_repeat('c', 64),
        'license' => 'MIT',
        'mode' => 'import',
        'divisions_count' => 0,
        'districts_count' => 0,
        'upazilas_count' => 0,
        'unions_count' => 0,
        'deactivated_count' => 0,
        'imported_at' => now(),
    ]);

    $afterReimport = $lookup->childrenOf(BdLocationType::Division, $chain['division']->source_id);
    expect($afterReimport)->toHaveCount(2)
        ->and(collect($afterReimport)->pluck('id'))->toContain($secondDistrict->id);
});

it('returns the top-level divisions', function () {
    addressTestLocationChain();

    $rows = app(BdLocationChildren::class)->topLevel();

    expect($rows)->not->toBeEmpty()
        ->and($rows[0])->toHaveKeys(['id', 'source_id', 'name_en', 'name_bn']);
});
