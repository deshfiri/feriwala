<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Kyc\Actions\OpenKycDraft;
use App\Domain\Kyc\Actions\PublishKycBatchVersion;
use App\Domain\Kyc\Actions\StartKycResubmission;
use App\Domain\Kyc\Actions\SubmitKyc;
use App\Domain\Kyc\Batch\AssignKycBatch;
use App\Domain\Kyc\Batch\KycCompletion;
use App\Domain\Kyc\Batch\KycEvidenceRefused;
use App\Domain\Kyc\Batch\PartnerKycRound;
use App\Domain\Kyc\Batch\RecordKycEvidence;
use App\Domain\Kyc\Batch\SupplierKycRound;
use App\Domain\Kyc\Enums\KycAudience;
use App\Domain\Kyc\Enums\KycCompletionRule;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Exceptions\KycIncomplete;
use App\Domain\Kyc\KycDocumentStore;
use App\Domain\Kyc\Models\KycBatch;
use App\Domain\Kyc\Models\KycBatchGroup;
use App\Domain\Kyc\Models\KycDocument;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycEvidenceReview;
use App\Domain\Supplier\Actions\OpenSupplierKycRound;
use App\Domain\Supplier\Actions\SubmitSupplierKyc;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierKycDocument;
use App\Domain\Supplier\Models\SupplierKycEvidenceReview;
use App\Domain\Supplier\SupplierKycDocumentStore;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
 * KYC batch rounds (D30, commit 2): group completion rules, evidence slots, and
 * rounds pinned to an immutable batch version — for Partners and Suppliers, on
 * the same engine and separate data.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('kyc');
    Notification::fake();
});

/**
 * A requirement made of the given slots: [key => [evidence, required]].
 *
 * @param  array<string, array{0: string, 1?: bool}>  $slots
 */
function roundRequirement(string $key, array $slots, string $name = ''): KycDocumentType
{
    // A requirement is made once per test, however many batches ask for it.
    $existing = KycDocumentType::query()->where('key', $key)->first();

    if ($existing !== null) {
        return $existing;
    }

    $type = KycDocumentType::factory()->key($key)->create([
        'name' => $name !== '' ? $name : ucfirst(str_replace('_', ' ', $key)),
        'audience' => KycAudience::Both,
    ]);

    foreach (array_values(array_map(null, array_keys($slots), $slots)) as $order => [$slotKey, $spec]) {
        $type->slots()->create([
            'key' => $slotKey,
            'label' => ucfirst($slotKey),
            'is_required' => $spec[1] ?? true,
            'evidence_type' => $spec[0],
            'accepted_mime_types' => in_array($spec[0], ['value', 'declaration'], true) ? null : ($spec[0] === 'pdf' ? ['application/pdf'] : ['image/jpeg', 'image/png']),
            'max_size_kb' => in_array($spec[0], ['value', 'declaration'], true) ? null : 2048,
            'is_sensitive' => $spec[0] === 'value',
            'sort_order' => $order,
        ]);
    }

    return $type;
}

/**
 * A batch of groups: [[key, rule, minimum, required, [requirement, ...]], ...].
 *
 * @param  array<int, array{0: string, 1: KycCompletionRule, 2: int|null, 3: bool, 4: array<int, KycDocumentType>}>  $groups
 * @param  array<string, mixed>  $attributes
 */
function roundBatch(string $key, array $groups, array $attributes = []): KycBatch
{
    $batch = KycBatch::create(['key' => $key, 'name' => ucfirst($key), 'audience' => KycAudience::Both, ...$attributes]);

    foreach ($groups as $order => [$groupKey, $rule, $minimum, $required, $types]) {
        $group = KycBatchGroup::create([
            'kyc_batch_id' => $batch->id,
            'key' => $groupKey,
            'name' => ucfirst($groupKey),
            'completion_rule' => $rule,
            'minimum_required' => $minimum,
            'is_required' => $required,
            'sort_order' => $order,
        ]);

        foreach ($types as $position => $type) {
            $group->items()->create(['kyc_document_type_id' => $type->id, 'sort_order' => $position]);
        }
    }

    return $batch;
}

/** NID (front + back), passport (bio page + optional visa), driving licence. */
function roundIdentityTypes(): array
{
    return [
        'nid' => roundRequirement('national_id', ['front' => ['image'], 'back' => ['image']], 'National ID'),
        'passport' => roundRequirement('passport', ['bio_page' => ['image'], 'visa' => ['image', false]], 'Passport'),
        'licence' => roundRequirement('driving_licence', ['card' => ['image']], 'Driving licence'),
    ];
}

function roundImage(string $name = 'photo.jpg', int $kb = 100): UploadedFile
{
    return UploadedFile::fake()->image($name)->size($kb);
}

function partnerRound(?KycBatch $default = null): PartnerKycRound
{
    if ($default !== null) {
        $default->forceFill(['is_default' => true])->save();
    }

    $account = testBusinessAccount(AccountStatus::KycPending);
    $submission = app(OpenKycDraft::class)->handle($account);

    return new PartnerKycRound($submission, app(KycDocumentStore::class));
}

function supplierRound(?KycBatch $default = null): SupplierKycRound
{
    if ($default !== null) {
        $default->forceFill(['is_default' => true])->save();
    }

    $supplier = Supplier::factory()->kycPending()->create();
    $submission = app(OpenSupplierKycRound::class)->handle($supplier);

    return new SupplierKycRound($submission, app(SupplierKycDocumentStore::class));
}

function roundComplete(PartnerKycRound|SupplierKycRound $round): bool
{
    return app(KycCompletion::class)->evaluate($round)->isComplete();
}

describe('which batch a round is opened with', function () {
    it('pins a new Partner round to the default batch\'s published version', function () {
        $batch = roundBatch('standard', [['identity', KycCompletionRule::Any, null, true, array_values(roundIdentityTypes())]]);

        $round = partnerRound($batch);

        expect($round->submission->kyc_batch_id)->toBe($batch->id)
            ->and($round->submission->kyc_batch_version_id)->not->toBeNull()
            ->and($round->definition()->version)->toBe(1)
            // No legacy snapshot rows: a versioned round does not use them.
            ->and($round->submission->requirements()->count())->toBe(0);
    });

    it('keeps the earlier flow when no default batch exists', function () {
        KycDocumentType::factory()->key('legacy_doc')->create();

        $round = partnerRound();

        expect($round->submission->kyc_batch_version_id)->toBeNull()
            ->and($round->submission->requirements()->count())->toBe(1);
    });

    it('never gives a Partner a Supplier batch, or a Supplier a Partner batch', function () {
        $types = roundIdentityTypes();

        $forSuppliers = roundBatch('supplier_only', [['identity', KycCompletionRule::Any, null, true, [$types['nid']]]], ['audience' => KycAudience::Supplier, 'is_default' => true]);
        $forPartners = roundBatch('partner_only', [['identity', KycCompletionRule::Any, null, true, [$types['passport']]]], ['audience' => KycAudience::Account, 'is_default' => true]);

        $partner = partnerRound();
        $supplier = supplierRound();

        expect($partner->submission->kyc_batch_id)->toBe($forPartners->id)
            ->and($supplier->submission->kyc_batch_id)->toBe($forSuppliers->id);

        expect(fn () => app(AssignKycBatch::class)->forPartner(partnerRound()->submission->fresh()->forceFill(['kyc_batch_id' => null, 'kyc_batch_version_id' => null]), $forSuppliers))
            ->toThrow(InvalidArgumentException::class);
    });

    it('serves both audiences from a batch for both, after their own default', function () {
        $batch = roundBatch('everyone', [['identity', KycCompletionRule::Any, null, true, [roundIdentityTypes()['nid']]]], ['audience' => KycAudience::Both, 'is_default' => true]);

        expect(partnerRound()->submission->kyc_batch_id)->toBe($batch->id)
            ->and(supplierRound()->submission->kyc_batch_id)->toBe($batch->id);
    });

    it('refuses to move a round that already holds evidence', function () {
        $first = roundBatch('first', [['identity', KycCompletionRule::Any, null, true, [roundIdentityTypes()['nid']]]]);
        $round = partnerRound($first);

        app(RecordKycEvidence::class)->file($round, 'national_id', 'front', roundImage());

        $other = roundBatch('other', [['g', KycCompletionRule::All, null, true, [roundRequirement('tin', ['value' => ['value']])]]]);

        expect(fn () => app(AssignKycBatch::class)->forPartner($round->submission, $other))->toThrow(InvalidArgumentException::class);
    });
});

describe('a round keeps the version it was opened against', function () {
    it('is not changed when the batch is edited and republished, and new rounds get the new version', function () {
        $types = roundIdentityTypes();
        $batch = roundBatch('standard', [['identity', KycCompletionRule::Any, null, true, [$types['nid'], $types['passport']]]]);

        $open = partnerRound($batch);
        $v1Names = collect($open->definition()->groups()[0]['requirements'])->pluck('key')->all();

        // The administrator drops the passport, tightens the NID limit, and republishes.
        $batch->groups()->sole()->items()->where('kyc_document_type_id', $types['passport']->id)->delete();
        $types['nid']->slots()->where('key', 'front')->update(['max_size_kb' => 10]);
        $v2 = app(PublishKycBatchVersion::class)->handle($batch);

        expect($v2->version)->toBe(2)
            // The open round still has both options and the old limit…
            ->and(collect($open->definition()->groups()[0]['requirements'])->pluck('key')->all())->toBe($v1Names)
            ->and($open->definition()->slot('national_id', 'front')['max_kb'])->toBe(2048);

        // …and still accepts a file the new limit would refuse.
        app(RecordKycEvidence::class)->file($open, 'national_id', 'front', roundImage('front.jpg', 500));

        // A round opened now gets version 2.
        $fresh = partnerRound();

        expect($fresh->definition()->version)->toBe(2)
            ->and($fresh->definition()->slot('national_id', 'front')['max_kb'])->toBe(10);
    });

    it('stays on the same version when a Partner resubmits after a rejection', function () {
        $batch = roundBatch('standard', [['identity', KycCompletionRule::Any, null, true, [roundIdentityTypes()['nid']]]]);
        $round = partnerRound($batch);

        $round->submission->forceFill(['status' => KycStatus::Rejected])->save();
        $batch->groups()->sole()->update(['name' => 'Renamed after']);
        app(PublishKycBatchVersion::class)->handle($batch);

        $next = app(StartKycResubmission::class)->handle($round->submission->businessAccount);

        expect($next->round)->toBe(2)
            ->and($next->kyc_batch_version_id)->toBe($round->submission->kyc_batch_version_id)
            ->and($next->requirements()->count())->toBe(0);
    });
});

describe('group completion rules, decided on the server', function () {
    it('"any one": the NID needs front and back, the passport and the licence need one file each', function () {
        $round = partnerRound(roundBatch('standard', [['identity', KycCompletionRule::Any, null, true, array_values(roundIdentityTypes())]]));
        $evidence = app(RecordKycEvidence::class);

        expect(roundComplete($round))->toBeFalse();

        // Half the NID is not the NID.
        $evidence->file($round, 'national_id', 'front', roundImage());

        expect(roundComplete($round))->toBeFalse();

        $evidence->file($round, 'national_id', 'back', roundImage());

        expect(roundComplete($round))->toBeTrue();
    });

    it('"any one": a passport alone satisfies identity, with no NID or licence', function () {
        $round = partnerRound(roundBatch('standard', [['identity', KycCompletionRule::Any, null, true, array_values(roundIdentityTypes())]]));

        app(RecordKycEvidence::class)->file($round, 'passport', 'bio_page', roundImage());

        // The optional visa page is not owed.
        expect(roundComplete($round))->toBeTrue();
    });

    it('"any one": a driving licence alone satisfies identity', function () {
        $round = partnerRound(roundBatch('standard', [['identity', KycCompletionRule::Any, null, true, array_values(roundIdentityTypes())]]));

        app(RecordKycEvidence::class)->file($round, 'driving_licence', 'card', roundImage());

        expect(roundComplete($round))->toBeTrue();
    });

    it('"all required": every required requirement, and optional ones are not owed', function () {
        $licence = roundRequirement('trade_licence', ['file' => ['pdf']]);
        $tin = roundRequirement('tin', ['number' => ['value']]);
        $bank = roundRequirement('bank_statement', ['file' => ['pdf']]);

        $batch = roundBatch('business', [['business', KycCompletionRule::All, null, true, [$licence, $tin, $bank]]]);
        $batch->groups()->sole()->items()->where('kyc_document_type_id', $bank->id)->update(['is_required' => false]);

        $round = partnerRound($batch);
        $evidence = app(RecordKycEvidence::class);

        $evidence->file($round, 'trade_licence', 'file', UploadedFile::fake()->create('licence.pdf', 50, 'application/pdf'));

        expect(roundComplete($round))->toBeFalse();

        $evidence->value($round, 'tin', 'number', '123456789012');

        expect(roundComplete($round))->toBeTrue();
    });

    it('"minimum": any 2 of 3, counting only the options chosen and completed', function () {
        $statement = roundRequirement('bank_statement', ['file' => ['pdf']]);
        $bill = roundRequirement('utility_bill', ['file' => ['pdf']]);
        $rental = roundRequirement('rental_agreement', ['file' => ['pdf']]);

        $round = partnerRound(roundBatch('address', [['proof', KycCompletionRule::Minimum, 2, true, [$statement, $bill, $rental]]]));
        $evidence = app(RecordKycEvidence::class);
        $pdf = fn (string $name) => UploadedFile::fake()->create($name, 50, 'application/pdf');

        $evidence->file($round, 'bank_statement', 'file', $pdf('a.pdf'));

        expect(roundComplete($round))->toBeFalse()
            ->and(app(KycCompletion::class)->evaluate($round)->groups[0]['done'])->toBe(1)
            ->and(app(KycCompletion::class)->evaluate($round)->groups[0]['needed'])->toBe(2);

        $evidence->file($round, 'utility_bill', 'file', $pdf('b.pdf'));

        expect(roundComplete($round))->toBeTrue();
    });

    it('an optional group never blocks, a required group does', function () {
        $types = roundIdentityTypes();
        $extra = roundRequirement('reference', ['file' => ['pdf']]);

        $round = partnerRound(roundBatch('mixed', [
            ['identity', KycCompletionRule::Any, null, true, [$types['passport']]],
            ['extra', KycCompletionRule::All, null, false, [$extra]],
        ]));

        app(RecordKycEvidence::class)->file($round, 'passport', 'bio_page', roundImage());

        expect(roundComplete($round))->toBeTrue();
    });

    it('says in words what is still owed', function () {
        $round = partnerRound(roundBatch('standard', [['identity', KycCompletionRule::Any, null, true, array_values(roundIdentityTypes())]]));

        app(RecordKycEvidence::class)->file($round, 'national_id', 'front', roundImage());

        $missing = implode(' | ', app(KycCompletion::class)->evaluate($round)->missing());

        expect($missing)->toContain('Identity')->toContain('National ID: Back');
    });
});

describe('the live selfie', function () {
    it('is not asked, optional, or required by the batch', function (string $mode, bool $needsIt, bool $isAsked) {
        $round = partnerRound(roundBatch('b', [['g', KycCompletionRule::Any, null, true, [roundIdentityTypes()['passport']]]], ['selfie_mode' => $mode]));

        app(RecordKycEvidence::class)->file($round, 'passport', 'bio_page', roundImage());

        $result = app(KycCompletion::class)->evaluate($round);

        expect($result->isComplete())->toBe(! $needsIt)
            ->and($result->selfie !== null)->toBe($isAsked);
    })->with([
        'disabled' => ['disabled', false, false],
        'optional' => ['optional', false, true],
        'required' => ['required', true, true],
    ]);

    it('must be a camera capture, with the capture facts stored, unless the batch allows an upload', function () {
        $round = partnerRound(roundBatch('b', [['g', KycCompletionRule::Any, null, true, [roundIdentityTypes()['passport']]]], ['selfie_mode' => 'required']));
        $evidence = app(RecordKycEvidence::class);

        expect(fn () => $evidence->file($round, '__selfie', 'capture', roundImage('selfie.jpg')))
            ->toThrow(KycEvidenceRefused::class);

        $evidence->file($round, '__selfie', 'capture', roundImage('selfie.jpg'), true, ['width' => 640, 'height' => 480, 'facing' => 'user']);

        $document = KycDocument::query()->sole();

        expect($document->captured_live)->toBeTrue()
            ->and($document->captured_at)->not->toBeNull()
            ->and($document->capture_meta)->toEqualCanonicalizing(['width' => 640, 'height' => 480, 'facing' => 'user'])
            ->and($document->kyc_document_type_id)->toBeNull();
    });

    it('accepts a picked file only when the batch explicitly allows the fallback', function () {
        $round = partnerRound(roundBatch('b', [['g', KycCompletionRule::Any, null, true, [roundIdentityTypes()['passport']]]], ['selfie_mode' => 'required', 'selfie_allows_upload' => true]));

        app(RecordKycEvidence::class)->file($round, '__selfie', 'capture', roundImage('selfie.jpg'));

        $document = KycDocument::query()->sole();

        // It was not a live capture, and is not recorded as one.
        expect($document->captured_live)->toBeFalse();
    });

    it('is replaced by a retake, and the old image goes while still a draft', function () {
        $round = partnerRound(roundBatch('b', [['g', KycCompletionRule::Any, null, true, [roundIdentityTypes()['passport']]]], ['selfie_mode' => 'required']));
        $evidence = app(RecordKycEvidence::class);

        $evidence->file($round, '__selfie', 'capture', roundImage('one.jpg'), true);
        $evidence->file($round, '__selfie', 'capture', roundImage('two.jpg'), true);

        expect(KycDocument::query()->count())->toBe(1)
            ->and(KycDocument::query()->sole()->original_name)->toBe('two.jpg');
    });

    it('is refused where the batch does not ask for one', function () {
        $round = partnerRound(roundBatch('b', [['g', KycCompletionRule::Any, null, true, [roundIdentityTypes()['passport']]]]));

        expect(fn () => app(RecordKycEvidence::class)->file($round, '__selfie', 'capture', roundImage(), true))
            ->toThrow(KycEvidenceRefused::class);
    });
});

describe('evidence rules', function () {
    it('enforces the slot\'s format, extension and size on the server', function () {
        $round = partnerRound(roundBatch('b', [['g', KycCompletionRule::Any, null, true, [roundIdentityTypes()['nid']]]]));
        $evidence = app(RecordKycEvidence::class);

        // A PDF where only images are accepted.
        expect(fn () => $evidence->file($round, 'national_id', 'front', UploadedFile::fake()->create('x.pdf', 50, 'application/pdf')))
            ->toThrow(KycEvidenceRefused::class);

        // Right type, over the limit.
        expect(fn () => $evidence->file($round, 'national_id', 'front', roundImage('big.jpg', 5000)))
            ->toThrow(KycEvidenceRefused::class);

        // An image type under a script's extension.
        expect(fn () => $evidence->file($round, 'national_id', 'front', UploadedFile::fake()->create('shell.php', 10, 'image/jpeg')))
            ->toThrow(KycEvidenceRefused::class);

        expect(KycDocument::query()->count())->toBe(0);
    });

    it('refuses an unknown slot, a file for a typed slot, and a value for a file slot', function () {
        $tin = roundRequirement('tin', ['number' => ['value']]);
        $round = partnerRound(roundBatch('b', [['g', KycCompletionRule::All, null, true, [$tin, roundIdentityTypes()['nid']]]]));
        $evidence = app(RecordKycEvidence::class);

        expect(fn () => $evidence->file($round, 'tin', 'nonsense', roundImage()))->toThrow(KycEvidenceRefused::class)
            ->and(fn () => $evidence->file($round, 'tin', 'number', roundImage()))->toThrow(KycEvidenceRefused::class)
            ->and(fn () => $evidence->value($round, 'national_id', 'front', '123'))->toThrow(KycEvidenceRefused::class);
    });

    it('stores a typed value encrypted and shows it masked', function () {
        $round = partnerRound(roundBatch('b', [['g', KycCompletionRule::All, null, true, [roundRequirement('tin', ['number' => ['value']])]]]));

        app(RecordKycEvidence::class)->value($round, 'tin', 'number', '123456789012');

        $item = $round->evidence()['tin|number'];

        expect($item->maskedValue)->toBe('••••••••9012')
            ->and(json_encode($item->toArray()))->not->toContain('123456789012')
            ->and(DB::table('kyc_submission_fields')->value('value'))->not->toContain('123456789012');
    });

    it('treats a declaration as a tick, and refuses to treat anything else as one', function () {
        $round = partnerRound(roundBatch('b', [['g', KycCompletionRule::All, null, true, [roundRequirement('declaration', ['agree' => ['declaration']])]]]));

        expect(roundComplete($round))->toBeFalse();

        app(RecordKycEvidence::class)->value($round, 'declaration', 'agree', 'yes please');

        expect(roundComplete($round))->toBeTrue()
            ->and(DB::table('kyc_submission_fields')->count())->toBe(1);
    });

    it('refuses a legacy round, and a round that can no longer be changed', function () {
        KycDocumentType::factory()->key('legacy_doc')->create();
        $legacy = partnerRound();

        expect(fn () => app(RecordKycEvidence::class)->file($legacy, 'legacy_doc', 'file', roundImage()))->toThrow(KycEvidenceRefused::class);

        $round = partnerRound(roundBatch('b', [['g', KycCompletionRule::Any, null, true, [roundIdentityTypes()['passport']]]]));
        $round->submission->forceFill(['status' => KycStatus::Submitted])->save();

        expect(fn () => app(RecordKycEvidence::class)->file($round, 'passport', 'bio_page', roundImage()))->toThrow(KycEvidenceRefused::class);
    });
});

describe('choosing between identity options', function () {
    it('clears the draft files of the option swapped out, and keeps the new one', function () {
        $round = partnerRound(roundBatch('standard', [['identity', KycCompletionRule::Any, null, true, array_values(roundIdentityTypes())]]));
        $evidence = app(RecordKycEvidence::class);

        $evidence->file($round, 'national_id', 'front', roundImage());
        $evidence->file($round, 'national_id', 'back', roundImage());
        $evidence->file($round, 'passport', 'bio_page', roundImage());

        $keys = array_keys($round->evidence());

        expect($keys)->toBe(['passport|bio_page'])
            ->and($round->selections())->toBe(['identity' => ['passport']])
            ->and(KycDocument::query()->count())->toBe(1);
    });

    it('can be chosen before anything is uploaded, so the form can show what it needs', function () {
        $round = partnerRound(roundBatch('standard', [['identity', KycCompletionRule::Any, null, true, array_values(roundIdentityTypes())]]));

        app(RecordKycEvidence::class)->choose($round, 'identity', 'driving_licence');

        expect($round->selections())->toBe(['identity' => ['driving_licence']]);
    });

    it('does not count a finished option the applicant walked away from', function () {
        $round = partnerRound(roundBatch('standard', [['identity', KycCompletionRule::Any, null, true, array_values(roundIdentityTypes())]]));

        app(RecordKycEvidence::class)->file($round, 'driving_licence', 'card', roundImage());
        app(RecordKycEvidence::class)->choose($round, 'identity', 'national_id');

        expect(roundComplete($round))->toBeFalse();
    });

    it('lets a "minimum" group hold several options, and withdraw one', function () {
        $a = roundRequirement('bank_statement', ['file' => ['pdf']]);
        $b = roundRequirement('utility_bill', ['file' => ['pdf']]);
        $round = partnerRound(roundBatch('address', [['proof', KycCompletionRule::Minimum, 2, true, [$a, $b]]]));
        $evidence = app(RecordKycEvidence::class);

        $evidence->choose($round, 'proof', 'bank_statement');
        $evidence->choose($round, 'proof', 'utility_bill');

        expect($round->selections()['proof'])->toBe(['bank_statement', 'utility_bill']);

        $evidence->deselect($round, 'proof', 'utility_bill');

        expect($round->selections()['proof'])->toBe(['bank_statement']);
    });

    it('offers no choice in an "all required" group', function () {
        $round = partnerRound(roundBatch('business', [['business', KycCompletionRule::All, null, true, [roundRequirement('tin', ['n' => ['value']])]]]));

        expect(fn () => app(RecordKycEvidence::class)->choose($round, 'business', 'tin'))->toThrow(KycEvidenceRefused::class);
    });
});

describe('the same rules for a Supplier, on the Supplier\'s own data', function () {
    it('completes a Supplier round by the same group rules, in the Supplier tables only', function () {
        $round = supplierRound(roundBatch('supplier_standard', [['identity', KycCompletionRule::Any, null, true, array_values(roundIdentityTypes())]], ['audience' => KycAudience::Supplier]));
        $evidence = app(RecordKycEvidence::class);

        $evidence->file($round, 'national_id', 'front', roundImage());

        expect(roundComplete($round))->toBeFalse();

        $evidence->file($round, 'national_id', 'back', roundImage());

        expect(roundComplete($round))->toBeTrue()
            ->and(SupplierKycDocument::query()->count())->toBe(2)
            ->and(KycDocument::query()->count())->toBe(0);
    });

    it('keeps a Supplier\'s selfie out of the Partner tables', function () {
        $round = supplierRound(roundBatch('supplier_standard', [['g', KycCompletionRule::Any, null, true, [roundIdentityTypes()['passport']]]], ['audience' => KycAudience::Supplier, 'selfie_mode' => 'required']));

        app(RecordKycEvidence::class)->file($round, '__selfie', 'capture', roundImage('s.jpg'), true);

        expect(SupplierKycDocument::query()->sole()->captured_live)->toBeTrue()
            ->and(KycDocument::query()->count())->toBe(0);
    });

    it('keeps a replaced file after submission, marked superseded, and only unlocks a rejected option', function () {
        $round = supplierRound(roundBatch('supplier_standard', [['identity', KycCompletionRule::Any, null, true, [roundIdentityTypes()['nid'], roundIdentityTypes()['passport']]]], ['audience' => KycAudience::Supplier]));
        $evidence = app(RecordKycEvidence::class);

        $evidence->file($round, 'national_id', 'front', roundImage('old.jpg'));
        $evidence->file($round, 'national_id', 'back', roundImage());

        // Submitted, then sent back for correction (the same round reopens).
        $round->submission->forceFill(['submitted_at' => now(), 'status' => 'correction_required'])->save();

        // The submitted option cannot simply be swapped for another…
        expect(fn () => $evidence->file($round, 'passport', 'bio_page', roundImage()))->toThrow(KycEvidenceRefused::class);

        // …until staff rejects what was submitted.
        foreach (['front', 'back'] as $slot) {
            SupplierKycEvidenceReview::create([
                'supplier_kyc_submission_id' => $round->submission->id,
                'requirement_key' => 'national_id',
                'slot_key' => $slot,
                'status' => 'rejected',
                'note' => 'Blurred.',
                'reviewed_by' => User::factory()->create()->id,
            ]);
        }

        $evidence->file($round, 'passport', 'bio_page', roundImage('passport.jpg'));

        // The submitted NID files were kept, superseded — never deleted.
        expect(SupplierKycDocument::query()->whereNotNull('superseded_at')->count())->toBe(2)
            ->and(array_keys($round->evidence()))->toBe(['passport|bio_page']);
    });
});

describe('submission is judged by the batch', function () {
    it('refuses a Partner round until every required group is satisfied, then submits', function () {
        $round = partnerRound(roundBatch('standard', [['identity', KycCompletionRule::Any, null, true, array_values(roundIdentityTypes())]], ['selfie_mode' => 'required']));
        $evidence = app(RecordKycEvidence::class);

        expect(fn () => app(SubmitKyc::class)->handle($round->submission))->toThrow(KycIncomplete::class);

        $evidence->file($round, 'passport', 'bio_page', roundImage());

        // The ID alone is not enough: the selfie is required too.
        expect(fn () => app(SubmitKyc::class)->handle($round->submission))->toThrow(KycIncomplete::class);

        $evidence->file($round, '__selfie', 'capture', roundImage('s.jpg'), true);

        app(SubmitKyc::class)->handle($round->submission);

        expect($round->submission->fresh()->status)->toBe(KycStatus::Submitted);
    });

    it('refuses a Supplier round until complete, then submits', function () {
        $round = supplierRound(roundBatch('supplier_standard', [['identity', KycCompletionRule::Any, null, true, [roundIdentityTypes()['passport']]]], ['audience' => KycAudience::Supplier]));

        expect(fn () => app(SubmitSupplierKyc::class)->handle($round->submission->supplier))->toThrow(InvalidArgumentException::class);

        app(RecordKycEvidence::class)->file($round, 'passport', 'bio_page', roundImage());

        app(SubmitSupplierKyc::class)->handle($round->submission->supplier);

        expect($round->submission->fresh()->status->value)->toBe('under_review');
    });
});

describe('a verdict judges the evidence that existed when it was given', function () {
    it('counts rejected evidence as missing, and a replacement as waiting for its own verdict', function () {
        $round = partnerRound(roundBatch('standard', [['identity', KycCompletionRule::Any, null, true, [roundIdentityTypes()['passport']]]]));
        $evidence = app(RecordKycEvidence::class);

        $evidence->file($round, 'passport', 'bio_page', roundImage('blurry.jpg'));

        KycEvidenceReview::create([
            'kyc_submission_id' => $round->submission->id,
            'requirement_key' => 'passport',
            'slot_key' => 'bio_page',
            'status' => 'rejected',
            'note' => 'Too blurry to read.',
            'reviewed_by' => testBusinessAccount()->owner->id,
        ]);

        expect(roundComplete($round))->toBeFalse()
            ->and($round->evidence()['passport|bio_page']->note)->toBe('Too blurry to read.');

        // The replacement is new evidence: the old verdict does not follow it.
        $this->travel(2)->seconds();
        $evidence->file($round, 'passport', 'bio_page', roundImage('clear.jpg'));

        expect($round->evidence()['passport|bio_page']->verdict)->toBeNull()
            ->and(roundComplete($round))->toBeTrue();
    });

    it('will not replace evidence a reviewer has accepted', function () {
        $round = partnerRound(roundBatch('standard', [['identity', KycCompletionRule::Any, null, true, [roundIdentityTypes()['passport']]]]));

        app(RecordKycEvidence::class)->file($round, 'passport', 'bio_page', roundImage());

        KycEvidenceReview::create([
            'kyc_submission_id' => $round->submission->id,
            'requirement_key' => 'passport',
            'slot_key' => 'bio_page',
            'status' => 'accepted',
            'reviewed_by' => testBusinessAccount()->owner->id,
        ]);

        expect(fn () => app(RecordKycEvidence::class)->file($round, 'passport', 'bio_page', roundImage()))->toThrow(KycEvidenceRefused::class);
    });
});
