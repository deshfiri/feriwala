<?php

use App\Domain\Kyc\Actions\BuildBatchDefinition;
use App\Domain\Kyc\Actions\PublishKycBatchVersion;
use App\Domain\Kyc\Enums\KycAudience;
use App\Domain\Kyc\Enums\KycCompletionRule;
use App\Domain\Kyc\Exceptions\InvalidKycBatch;
use App\Domain\Kyc\Models\KycBatch;
use App\Domain\Kyc\Models\KycBatchGroup;
use App\Domain\Kyc\Models\KycBatchVersion;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycRequirementSlot;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * KYC batches as versioned templates (D30, commit 1): the structure is live and
 * editable, a published version is frozen, and what existed before is mapped
 * mechanically rather than guessed.
 */

/**
 * A batch with one group holding the given requirements.
 *
 * @param  array<int, KycDocumentType>  $types
 */
function kycBatchWithGroup(string $key, array $types, KycCompletionRule $rule = KycCompletionRule::All, ?int $minimum = null, KycAudience $audience = KycAudience::Account): KycBatch
{
    $batch = KycBatch::create(['key' => $key, 'name' => ucfirst($key), 'audience' => $audience]);

    $group = KycBatchGroup::create([
        'kyc_batch_id' => $batch->id,
        'key' => 'main',
        'name' => 'Main',
        'completion_rule' => $rule,
        'minimum_required' => $minimum,
    ]);

    foreach ($types as $position => $type) {
        $group->items()->create(['kyc_document_type_id' => $type->id, 'sort_order' => $position]);
    }

    return $batch;
}

/**
 * A requirement with one required image slot.
 */
function kycRequirementWithSlot(string $key): KycDocumentType
{
    $type = KycDocumentType::factory()->key($key)->create(['name' => ucfirst($key)]);

    $type->slots()->create([
        'key' => 'file',
        'label' => ucfirst($key),
        'is_required' => true,
        'evidence_type' => 'image',
        'accepted_mime_types' => ['image/jpeg'],
        'max_size_kb' => 2048,
    ]);

    return $type;
}

describe('what existed before is mapped mechanically', function () {
    it('gives each old criterion the slots its own columns implied, and each old batch one all-required group', function () {
        $batch = KycBatch::create(['key' => 'general', 'name' => 'General verification', 'audience' => KycAudience::Account]);

        $photo = KycDocumentType::factory()->key('photo')->create([
            'kyc_batch_id' => $batch->id,
            'accepted_mime_types' => ['image/jpeg', 'image/png'],
        ]);
        $tin = KycDocumentType::factory()->key('tin')->optional()->create([
            'kyc_batch_id' => $batch->id,
            'requires_file' => true,
            'requires_value' => true,
            'value_label' => 'TIN number',
            'accepted_mime_types' => ['application/pdf'],
        ]);
        $orphan = KycDocumentType::factory()->key('orphan')->create(['kyc_batch_id' => null]);

        $migration = require base_path('database/migrations/2026_10_14_110000_create_versioned_kyc_batch_templates.php');
        (new ReflectionMethod($migration, 'backfill'))->invoke($migration);

        expect($photo->slots()->pluck('evidence_type')->map->value->all())->toBe(['image'])
            ->and($tin->slots()->pluck('key')->all())->toBe(['file', 'value'])
            ->and($tin->slots()->where('key', 'file')->sole()->evidence_type->value)->toBe('pdf')
            ->and($tin->slots()->where('key', 'value')->sole()->is_sensitive)->toBeTrue();

        $group = $batch->groups()->sole();

        expect($group->completion_rule)->toBe(KycCompletionRule::All)
            ->and($group->items->pluck('kyc_document_type_id')->sort()->values()->all())->toBe([$photo->id, $tin->id])
            // Each keeps its own required/optional flag: nobody is asked for more or less.
            ->and($group->items->firstWhere('kyc_document_type_id', $photo->id)->is_required)->toBeTrue()
            ->and($group->items->firstWhere('kyc_document_type_id', $tin->id)->is_required)->toBeFalse();

        // A criterion that sat in no batch is still asked today, so it is grouped, not dropped.
        $legacy = KycBatch::query()->where('key', 'legacy_ungrouped_account')->sole();

        expect($orphan->fresh()->kyc_batch_id)->toBe($legacy->id)
            ->and($legacy->groups()->sole()->items)->toHaveCount(1);
    });

    it('reads a requirement made by an older path with the slots its columns imply', function () {
        $legacy = KycDocumentType::factory()->key('licence')->create(['accepted_mime_types' => ['image/png']]);
        $batch = kycBatchWithGroup('b', [$legacy]);

        $slots = app(BuildBatchDefinition::class)->handle($batch)['groups'][0]['requirements'][0]['slots'];

        expect($slots)->toHaveCount(1)
            ->and($slots[0]['key'])->toBe('file')
            ->and($slots[0]['evidence'])->toBe('image');
    });
});

describe('publishing a version', function () {
    it('freezes the structure, and an unchanged republish returns the same version', function () {
        $batch = kycBatchWithGroup('standard', [kycRequirementWithSlot('trade_licence')]);

        $first = app(PublishKycBatchVersion::class)->handle($batch);
        $again = app(PublishKycBatchVersion::class)->handle($batch);

        expect($first->version)->toBe(1)
            ->and($again->is($first))->toBeTrue()
            ->and($batch->fresh()->published_version)->toBe(1)
            ->and($first->definition['groups'][0]['requirements'][0]['key'])->toBe('trade_licence')
            ->and($first->definition['groups'][0]['requirements'][0]['slots'][0]['max_kb'])->toBe(2048);
    });

    it('does not change a published version when the live batch is edited', function () {
        $licence = kycRequirementWithSlot('trade_licence');
        $batch = kycBatchWithGroup('standard', [$licence]);

        $v1 = app(PublishKycBatchVersion::class)->handle($batch);

        // Edit everything an administrator can: rename, loosen the limit, add an option.
        $batch->groups()->sole()->update(['name' => 'Renamed']);
        $licence->slots()->sole()->update(['max_size_kb' => 99999]);
        $batch->groups()->sole()->items()->create([
            'kyc_document_type_id' => kycRequirementWithSlot('tin')->id,
            'sort_order' => 1,
        ]);

        expect(KycBatchVersion::query()->find($v1->id)->definition['groups'][0]['name'])->toBe('Main')
            ->and(KycBatchVersion::query()->find($v1->id)->definition['groups'][0]['requirements'])->toHaveCount(1)
            ->and(KycBatchVersion::query()->find($v1->id)->definition['groups'][0]['requirements'][0]['slots'][0]['max_kb'])->toBe(2048);

        $v2 = app(PublishKycBatchVersion::class)->handle($batch);

        expect($v2->version)->toBe(2)
            ->and($v2->definition['groups'][0]['name'])->toBe('Renamed')
            ->and($v2->definition['groups'][0]['requirements'])->toHaveCount(2)
            ->and($batch->fresh()->versions->pluck('version')->all())->toBe([2, 1]);
    });

    it('leaves out a requirement that has been switched off', function () {
        $off = kycRequirementWithSlot('old_form');
        $off->forceFill(['is_active' => false])->save();

        $batch = kycBatchWithGroup('standard', [kycRequirementWithSlot('licence'), $off]);

        $keys = collect(app(BuildBatchDefinition::class)->handle($batch)['groups'][0]['requirements'])->pluck('key')->all();

        expect($keys)->toBe(['licence']);
    });

    it('publishes the first version of a batch carried over from before versions existed', function () {
        $batch = kycBatchWithGroup('carried_over', [kycRequirementWithSlot('licence')]);

        expect($batch->published_version)->toBe(0);

        $version = app(PublishKycBatchVersion::class)->latestOrPublish($batch);

        expect($version->version)->toBe(1)
            ->and(app(PublishKycBatchVersion::class)->latestOrPublish($batch->fresh())->is($version))->toBeTrue();
    });

    it('can never be edited or deleted once published', function () {
        $version = app(PublishKycBatchVersion::class)->handle(kycBatchWithGroup('standard', [kycRequirementWithSlot('licence')]));

        expect(fn () => DB::transaction(fn () => DB::table('kyc_batch_versions')->where('id', $version->id)->update(['checksum' => str_repeat('0', 64)])))
            ->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => DB::table('kyc_batch_versions')->where('id', $version->id)->delete()))
            ->toThrow(QueryException::class);
    });
});

describe('a batch nobody could complete is not published', function () {
    it('refuses an empty group, a minimum above the options, and a requirement with nothing required', function () {
        $none = KycDocumentType::factory()->key('slotless')->create(['name' => 'Slotless', 'requires_file' => false, 'requires_value' => false]);
        $one = kycRequirementWithSlot('one');

        $batch = kycBatchWithGroup('broken', [$one], KycCompletionRule::Minimum, 3);
        KycBatchGroup::create(['kyc_batch_id' => $batch->id, 'key' => 'empty', 'name' => 'Empty', 'completion_rule' => 'all', 'sort_order' => 1]);
        $batch->groups()->where('key', 'empty')->sole()->items()->create(['kyc_document_type_id' => $none->id]);

        try {
            app(PublishKycBatchVersion::class)->handle($batch);
            $this->fail('A broken batch was published.');
        } catch (InvalidKycBatch $invalid) {
            expect($invalid->problems)->toHaveCount(2)
                ->and(implode(' ', $invalid->problems))->toContain('Main')->toContain('Slotless');
        }

        expect($batch->fresh()->published_version)->toBe(0)
            ->and(KycBatchVersion::query()->count())->toBe(0);
    });

    it('refuses a batch that asks for nothing at all', function () {
        $batch = KycBatch::create(['key' => 'blank', 'name' => 'Blank']);

        expect(fn () => app(PublishKycBatchVersion::class)->handle($batch))->toThrow(InvalidKycBatch::class);
    });

    it('accepts a batch whose only request is the live selfie', function () {
        $batch = KycBatch::create(['key' => 'selfie_only', 'name' => 'Selfie only', 'selfie_mode' => 'required']);

        expect(app(PublishKycBatchVersion::class)->handle($batch)->definition['batch']['selfie']['mode'])->toBe('required');
    });
});

describe('defaults', function () {
    it('allows one default batch per audience, and not two', function () {
        KycBatch::create(['key' => 'partner_default', 'name' => 'Partner default', 'audience' => KycAudience::Account, 'is_default' => true]);
        KycBatch::create(['key' => 'supplier_default', 'name' => 'Supplier default', 'audience' => KycAudience::Supplier, 'is_default' => true]);

        expect(fn () => DB::transaction(fn () => KycBatch::create(['key' => 'another', 'name' => 'Another', 'audience' => KycAudience::Account, 'is_default' => true])))
            ->toThrow(QueryException::class);
    });
});

it('maps a requirement the old way and the new way to the same slot shape', function () {
    $type = KycDocumentType::factory()->key('x')->create();

    expect(KycRequirementSlot::derivedFor($type)[0])->toHaveKeys(['key', 'label', 'required', 'evidence', 'mimes', 'max_kb', 'sensitive']);
});
