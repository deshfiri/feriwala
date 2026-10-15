<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Kyc\Actions\ManageKycDocumentTypes;
use App\Domain\Kyc\Enums\KycAudience;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycBatch;
use App\Domain\Kyc\Models\KycDocument;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycEvidenceReview;
use App\Domain\Kyc\Models\KycRequirementSlot;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierKycDocument;
use App\Domain\Supplier\Models\SupplierKycSubmission;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StandardKycBatchSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The administrator's side of KYC batches (D30, commit 4): building a template,
 * previewing, cloning, publishing, activating and defaulting it; opening a round
 * against one; and reviewing a round group by group, slot by slot.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('kyc');
    Notification::fake();

    $this->admin = testPlatformStaff(PlatformRole::SuperAdmin);
});

/**
 * What the builder posts: any one of NID (two files) or passport (one), and an
 * all-required business group with a typed TIN.
 *
 * @return array<string, array<int, array<string, mixed>>>
 */
function adminBatchStructure(): array
{
    $image = ['evidence' => 'image', 'required' => true, 'mimes' => ['image/jpeg', 'image/png'], 'max_kb' => 2048];

    return ['groups' => [
        [
            'name' => 'Identity', 'rule' => 'any', 'required' => true,
            'requirements' => [
                ['name' => 'National ID', 'required' => true, 'slots' => [
                    ['label' => 'Front side', ...$image],
                    ['label' => 'Back side', ...$image],
                ]],
                ['name' => 'Passport', 'required' => true, 'slots' => [
                    ['label' => 'Photo page', ...$image],
                ]],
            ],
        ],
        [
            'name' => 'Business', 'rule' => 'all', 'required' => true,
            'requirements' => [
                ['name' => 'TIN', 'required' => true, 'slots' => [
                    ['label' => 'TIN number', 'evidence' => 'value', 'required' => true, 'sensitive' => true],
                ]],
            ],
        ],
    ]];
}

/** Builds a template through the screens' own endpoints, so the whole path is exercised. */
function adminBatchBuilt(User $admin, string $audience = 'account', string $name = 'Standard ID', string $selfie = 'disabled'): KycBatch
{
    test()->actingAs($admin, 'web')->post(route('admin.kyc.batch-builder.store'), [
        'name' => $name, 'audience' => $audience, 'selfie_mode' => $selfie, 'selfie_allows_upload' => 0,
    ])->assertSessionHasNoErrors();

    $batch = KycBatch::query()->latest('id')->firstOrFail();

    test()->actingAs($admin, 'web')->put(route('admin.kyc.batch-builder.structure', $batch), adminBatchStructure())
        ->assertSessionHasNoErrors();

    return $batch->refresh();
}

function adminBatchLive(User $admin, string $audience = 'account', string $selfie = 'disabled'): KycBatch
{
    $batch = adminBatchBuilt($admin, $audience, 'Standard ID '.$audience, $selfie);

    test()->actingAs($admin, 'web')->patch(route('admin.kyc.batch-builder.active', $batch), ['is_active' => 1])->assertSessionHasNoErrors();
    test()->actingAs($admin, 'web')->patch(route('admin.kyc.batch-builder.default', $batch), ['is_default' => 1])->assertSessionHasNoErrors();

    return $batch->refresh();
}

function adminBatchKey(KycBatch $batch, string $name): string
{
    return (string) KycDocumentType::query()->where('kyc_batch_id', $batch->id)->where('name', $name)->value('key');
}

function adminBatchImage(string $name = 'photo.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name)->size(100);
}

describe('building a template', function () {
    it('creates it switched off, with a key made from its name', function () {
        $batch = adminBatchBuilt($this->admin, 'account', 'Standard ID');

        expect($batch->key)->toBe('standard_id')
            ->and($batch->is_active)->toBeFalse()
            ->and($batch->is_default)->toBeFalse()
            ->and($batch->published_version)->toBe(0);
    });

    it('saves groups, requirements and slots with stable keys, and keeps the older columns in step', function () {
        $batch = adminBatchBuilt($this->admin);

        $nid = KycDocumentType::query()->where('kyc_batch_id', $batch->id)->where('name', 'National ID')->sole();

        expect($batch->groups()->pluck('key')->all())->toBe(['identity', 'business'])
            ->and($nid->key)->toBe('standard_id_national_id')
            ->and($nid->slots()->active()->pluck('key')->all())->toBe(['front_side', 'back_side'])
            ->and($nid->requires_file)->toBeTrue()
            ->and($nid->requires_value)->toBeFalse()
            ->and($nid->audience)->toBe(KycAudience::Account);

        $tin = KycDocumentType::query()->where('kyc_batch_id', $batch->id)->where('name', 'TIN')->sole();

        expect($tin->requires_value)->toBeTrue()
            ->and($tin->slots()->sole()->is_sensitive)->toBeTrue();
    });

    it('keeps an item\'s key when it is renamed, and retires what is taken out instead of deleting it', function () {
        $batch = adminBatchBuilt($this->admin);

        $this->actingAs($this->admin, 'web')->get(route('admin.kyc.batch-builder.edit', $batch))->assertInertia(function (Assert $page) use ($batch) {
            $groups = $page->toArray()['props']['groups'];

            // Rename the National ID, drop the passport and the second slot.
            $groups[0]['requirements'][0]['name'] = 'NID card';
            $groups[0]['requirements'][0]['slots'] = [$groups[0]['requirements'][0]['slots'][0]];
            $groups[0]['requirements'] = [$groups[0]['requirements'][0]];

            test()->actingAs(test()->admin, 'web')->put(route('admin.kyc.batch-builder.structure', $batch), ['groups' => $groups])
                ->assertSessionHasNoErrors();
        });

        $nid = KycDocumentType::query()->where('key', 'standard_id_national_id')->sole();
        $passport = KycDocumentType::query()->where('key', 'standard_id_passport')->sole();

        expect($nid->name)->toBe('NID card')
            ->and($nid->slots()->active()->count())->toBe(1)
            ->and(KycRequirementSlot::query()->where('key', 'back_side')->sole()->archived_at)->not->toBeNull()
            ->and($passport->archived_at)->not->toBeNull();
    });

    it('refuses an item that belongs to another batch rather than adopting it', function () {
        $mine = adminBatchBuilt($this->admin);
        $theirs = adminBatchBuilt($this->admin, 'account', 'Other batch');

        $foreign = KycDocumentType::query()->where('kyc_batch_id', $theirs->id)->where('name', 'TIN')->value('public_id');

        $payload = adminBatchStructure();
        $payload['groups'][1]['requirements'][0]['id'] = $foreign;

        $this->actingAs($this->admin, 'web')->put(route('admin.kyc.batch-builder.structure', $mine), $payload)->assertSessionHasErrors('groups');

        expect(KycDocumentType::query()->where('public_id', $foreign)->value('kyc_batch_id'))->toBe($theirs->id);
    });

    it('lists what would stop it being published, and refuses to publish it', function () {
        $batch = adminBatchBuilt($this->admin);

        $payload = adminBatchStructure();
        $payload['groups'][0]['rule'] = 'min';
        $payload['groups'][0]['minimum'] = 5;

        $this->actingAs($this->admin, 'web')->put(route('admin.kyc.batch-builder.structure', $batch), $payload)->assertSessionHasNoErrors();

        $this->actingAs($this->admin, 'web')->get(route('admin.kyc.batch-builder.edit', $batch))
            ->assertInertia(fn (Assert $page) => $page->has('problems', 1));

        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.batch-builder.publish', $batch))->assertSessionHasErrors('publish');

        expect($batch->refresh()->published_version)->toBe(0);
    });

    it('needs a number for a minimum group', function () {
        $batch = adminBatchBuilt($this->admin);

        $payload = adminBatchStructure();
        $payload['groups'][0]['rule'] = 'min';

        $this->actingAs($this->admin, 'web')->put(route('admin.kyc.batch-builder.structure', $batch), $payload)->assertSessionHasErrors('groups.0.minimum');
    });

    it('says when the live structure differs from what was published', function () {
        $batch = adminBatchBuilt($this->admin);

        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.batch-builder.publish', $batch))->assertSessionHasNoErrors();

        $this->actingAs($this->admin, 'web')->get(route('admin.kyc.batch-builder.edit', $batch))
            ->assertInertia(fn (Assert $page) => $page->where('batch.published_version', 1)->where('batch.has_unpublished_changes', false));

        $payload = adminBatchStructure();
        $payload['groups'][1]['name'] = 'Trade';

        $this->actingAs($this->admin, 'web')->put(route('admin.kyc.batch-builder.structure', $batch), $payload);

        $this->actingAs($this->admin, 'web')->get(route('admin.kyc.batch-builder.edit', $batch))
            ->assertInertia(fn (Assert $page) => $page->where('batch.has_unpublished_changes', true));
    });

    it('is previewed as the applicant\'s own form, in either language, without storing anything', function () {
        $batch = adminBatchBuilt($this->admin);
        $before = KycSubmission::query()->count() + KycDocument::query()->count();

        $this->actingAs($this->admin, 'web')->get(route('admin.kyc.batch-builder.preview', $batch))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/kyc/batches/preview')
                ->where('form.groups.0.name', 'Identity')
                ->where('form.groups.0.options.0.slots.0.label', 'Front side')
                ->where('form.editable', true)
                ->where('form.complete', false));

        $this->actingAs($this->admin, 'web')->get(route('admin.kyc.batch-builder.preview', [$batch, 'as' => 'bn']))
            ->assertInertia(fn (Assert $page) => $page->where('as', 'bn'));

        expect(KycSubmission::query()->count() + KycDocument::query()->count())->toBe($before);
    });
});

/*
 * The hierarchy is Batch -> Group -> Option -> Evidence slot. An option is what an
 * applicant chooses (National ID, Passport, Driving Licence); the files that make
 * one option complete (front, back) are its slots, never options of their own.
 */
describe('the batch, group, option and slot hierarchy', function () {
    beforeEach(function () {
        $image = ['evidence' => 'image', 'required' => true, 'mimes' => ['image/jpeg', 'image/png'], 'max_kb' => 2048];

        test()->actingAs($this->admin, 'web')->post(route('admin.kyc.batch-builder.store'), [
            'name' => 'Standard KYC', 'audience' => 'account', 'selfie_mode' => 'disabled', 'selfie_allows_upload' => 0,
        ]);

        $this->batch = KycBatch::query()->latest('id')->firstOrFail();

        $this->actingAs($this->admin, 'web')->put(route('admin.kyc.batch-builder.structure', $this->batch), ['groups' => [
            ['name' => 'Identity Verification', 'rule' => 'any', 'required' => true, 'requirements' => [
                ['name' => 'National ID', 'required' => true, 'slots' => [['label' => 'Front side', ...$image], ['label' => 'Back side', ...$image]]],
                ['name' => 'Passport', 'required' => true, 'slots' => [['label' => 'Bio-data page', ...$image]]],
                ['name' => 'Driving Licence', 'required' => true, 'slots' => [['label' => 'Front side', ...$image], ['label' => 'Back side', ...$image]]],
            ]],
            ['name' => 'Business', 'rule' => 'all', 'required' => true, 'requirements' => [
                ['name' => 'TIN', 'required' => true, 'slots' => [['label' => 'TIN number', 'evidence' => 'value', 'required' => true, 'sensitive' => true]]],
            ]],
        ]])->assertSessionHasNoErrors();

        $this->actingAs($this->admin, 'web')->patch(route('admin.kyc.batch-builder.active', $this->batch), ['is_active' => 1]);
        $this->actingAs($this->admin, 'web')->patch(route('admin.kyc.batch-builder.default', $this->batch), ['is_default' => 1]);

        $this->applicant = testBusinessAccount(AccountStatus::KycPending)->owner;
    });

    it('keeps the slots inside their option, and the options inside their group, in the published version', function () {
        $definition = $this->batch->latestVersion()->definition;

        expect(array_column($definition['groups'], 'name'))->toBe(['Identity Verification', 'Business'])
            ->and($definition['groups'][0]['rule'])->toBe('any')
            ->and(array_column($definition['groups'][0]['requirements'], 'name'))->toBe(['National ID', 'Passport', 'Driving Licence'])
            ->and(array_column($definition['groups'][0]['requirements'][0]['slots'], 'label'))->toBe(['Front side', 'Back side'])
            ->and(array_column($definition['groups'][0]['requirements'][1]['slots'], 'label'))->toBe(['Bio-data page'])
            ->and($definition['groups'][1]['rule'])->toBe('all');
    });

    it('shows the applicant one group with three options to choose between, each with its own slots', function () {
        $this->actingAs($this->applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('batch.groups', 2)
                ->where('batch.groups.0.choose', true)
                ->has('batch.groups.0.options', 3)
                ->has('batch.groups.0.options.0.slots', 2)
                ->has('batch.groups.0.options.1.slots', 1)
                ->has('batch.groups.0.options.2.slots', 2)
                ->where('batch.groups.1.choose', false));
    });

    it('completes the group only when every required slot of the chosen option is in', function () {
        $nid = adminBatchKey($this->batch, 'National ID');
        $passport = adminBatchKey($this->batch, 'Passport');

        $this->actingAs($this->applicant)->get(route('kyc.create'));

        $this->actingAs($this->applicant)->post(route('kyc.evidence.file'), ['requirement' => $nid, 'slot' => 'front_side', 'file' => adminBatchImage('front.jpg')]);

        // Half of the National ID is not a National ID.
        $this->actingAs($this->applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page->where('batch.groups.0.done', 0)->where('batch.groups.0.satisfied', false));

        $this->actingAs($this->applicant)->post(route('kyc.evidence.file'), ['requirement' => $nid, 'slot' => 'back_side', 'file' => adminBatchImage('back.jpg')]);

        $this->actingAs($this->applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page->where('batch.groups.0.done', 1)->where('batch.groups.0.satisfied', true));

        // Choosing the passport instead needs only the passport's own slot, and
        // the National ID files are not carried along with it.
        $this->actingAs($this->applicant)->post(route('kyc.evidence.file'), ['requirement' => $passport, 'slot' => 'bio_data_page', 'file' => adminBatchImage('bio.jpg')]);

        $this->actingAs($this->applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('batch.groups.0.satisfied', true)
                ->where('batch.groups.0.options.0.chosen', false)
                ->where('batch.groups.0.options.1.chosen', true)
                ->where('batch.groups.0.options.0.slots.0.current', null));

        expect(KycDocument::query()->count())->toBe(1);
    });

    it('does not let one required group stand in for another', function () {
        $this->actingAs($this->applicant)->get(route('kyc.create'));

        $this->actingAs($this->applicant)->post(route('kyc.evidence.file'), [
            'requirement' => adminBatchKey($this->batch, 'Passport'), 'slot' => 'bio_data_page', 'file' => adminBatchImage(),
        ]);

        // Identity is done; Business (all required) is not, so it cannot be sent.
        $this->actingAs($this->applicant)->post(route('kyc.submit'), ['confirm' => 1])->assertSessionHasErrors('submission');
    });
});

describe('taking a template live', function () {
    it('publishes it when activated, so what goes live is what was previewed', function () {
        $batch = adminBatchBuilt($this->admin);

        $this->actingAs($this->admin, 'web')->patch(route('admin.kyc.batch-builder.active', $batch), ['is_active' => 1])->assertSessionHasNoErrors();

        expect($batch->refresh()->is_active)->toBeTrue()
            ->and($batch->published_version)->toBe(1);
    });

    it('will not activate a batch no applicant could complete', function () {
        $batch = adminBatchBuilt($this->admin);

        $this->actingAs($this->admin, 'web')->put(route('admin.kyc.batch-builder.structure', $batch), ['groups' => [[
            'name' => 'Empty', 'rule' => 'all', 'required' => true, 'requirements' => [],
        ]]]);

        $this->actingAs($this->admin, 'web')->patch(route('admin.kyc.batch-builder.active', $batch), ['is_active' => 1])->assertSessionHasErrors('publish');

        expect($batch->refresh()->is_active)->toBeFalse();
    });

    it('keeps one default per audience, and a switched-off batch cannot be it', function () {
        $first = adminBatchLive($this->admin, 'account');
        $second = adminBatchBuilt($this->admin, 'account', 'Second');

        // Not switched on yet: refused.
        $this->actingAs($this->admin, 'web')->patch(route('admin.kyc.batch-builder.default', $second), ['is_default' => 1])->assertSessionHasErrors('is_default');

        $this->actingAs($this->admin, 'web')->patch(route('admin.kyc.batch-builder.active', $second), ['is_active' => 1]);
        $this->actingAs($this->admin, 'web')->patch(route('admin.kyc.batch-builder.default', $second), ['is_default' => 1])->assertSessionHasNoErrors();

        expect($first->refresh()->is_default)->toBeFalse()
            ->and($second->refresh()->is_default)->toBeTrue();

        // A different audience has its own default.
        $supplier = adminBatchLive($this->admin, 'supplier');

        expect($supplier->is_default)->toBeTrue()
            ->and($second->refresh()->is_default)->toBeTrue();
    });

    it('stops being the default when it is switched off', function () {
        $batch = adminBatchLive($this->admin);

        $this->actingAs($this->admin, 'web')->patch(route('admin.kyc.batch-builder.active', $batch), ['is_active' => 0]);

        expect($batch->refresh()->is_default)->toBeFalse();
    });

    it('clones into a separate, switched-off copy and leaves the original alone', function () {
        $source = adminBatchLive($this->admin);

        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.batch-builder.clone', $source))->assertSessionHasNoErrors();

        $copy = KycBatch::query()->latest('id')->firstOrFail();

        expect($copy->id)->not->toBe($source->id)
            ->and($copy->name)->toBe('Copy of '.$source->name)
            ->and($copy->is_active)->toBeFalse()
            ->and($copy->is_default)->toBeFalse()
            ->and($copy->published_version)->toBe(0)
            ->and($copy->groups()->count())->toBe(2);

        $copiedNid = KycDocumentType::query()->where('kyc_batch_id', $copy->id)->where('name', 'National ID')->sole();

        expect($copiedNid->key)->not->toBe(adminBatchKey($source, 'National ID'))
            ->and($copiedNid->slots()->active()->pluck('key')->all())->toBe(['front_side', 'back_side']);

        // The original still has its own requirements, its version and its default.
        expect($source->refresh()->is_default)->toBeTrue()
            ->and($source->published_version)->toBe(1)
            ->and(KycDocumentType::query()->where('kyc_batch_id', $source->id)->count())->toBe(3);
    });

    it('is refused to anyone without the permission to manage KYC settings', function () {
        $batch = adminBatchBuilt($this->admin);
        $staff = User::factory()->staff()->create();

        foreach ([
            fn () => test()->actingAs($staff)->get(route('admin.kyc.batch-builder.index')),
            fn () => test()->actingAs($staff)->get(route('admin.kyc.batch-builder.edit', $batch)),
            fn () => test()->actingAs($staff)->get(route('admin.kyc.batch-builder.preview', $batch)),
            fn () => test()->actingAs($staff)->put(route('admin.kyc.batch-builder.structure', $batch), adminBatchStructure()),
            fn () => test()->actingAs($staff)->post(route('admin.kyc.batch-builder.clone', $batch)),
            fn () => test()->actingAs($staff)->post(route('admin.kyc.batch-builder.publish', $batch)),
            fn () => test()->actingAs($staff)->patch(route('admin.kyc.batch-builder.active', $batch), ['is_active' => 1]),
            fn () => test()->actingAs($staff)->patch(route('admin.kyc.batch-builder.default', $batch), ['is_default' => 1]),
        ] as $attempt) {
            $attempt()->assertForbidden();
        }

        expect($batch->refresh()->is_active)->toBeFalse();
    });

    it('lists the templates', function () {
        adminBatchLive($this->admin);

        $this->actingAs($this->admin, 'web')->get(route('admin.kyc.batch-builder.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/kyc/batches/index')
                ->where('batches.0.is_default', true)
                ->where('batches.0.published_version', 1)
                ->where('batches.0.groups_count', 2)
                ->where('batches.0.has_unpublished_changes', false));
    });
});

describe('opening a round against a chosen template', function () {
    it('pins a Partner round to the template and version staff chose', function () {
        $default = adminBatchLive($this->admin);
        $other = adminBatchBuilt($this->admin, 'account', 'Short form');
        $this->actingAs($this->admin, 'web')->patch(route('admin.kyc.batch-builder.active', $other), ['is_active' => 1]);

        $account = testBusinessAccount(AccountStatus::Active);
        KycSubmission::create(['business_account_id' => $account->id, 'status' => KycStatus::Approved, 'round' => 1, 'submitted_at' => now(), 'reviewed_at' => now()]);

        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.request-update', $account), [
            'reason' => 'Periodic', 'instructions' => 'Please update.', 'batch' => $other->public_id,
        ])->assertSessionHasNoErrors();

        $round = KycSubmission::query()->where('business_account_id', $account->id)->orderByDesc('round')->firstOrFail();

        expect($round->kyc_batch_id)->toBe($other->id)->and($round->kyc_batch_version_id)->not->toBeNull()
            ->and($round->kyc_batch_id)->not->toBe($default->id);
    });

    it('refuses a batch meant for Suppliers on a Partner', function () {
        $supplierBatch = adminBatchLive($this->admin, 'supplier');

        $account = testBusinessAccount(AccountStatus::Active);
        KycSubmission::create(['business_account_id' => $account->id, 'status' => KycStatus::Approved, 'round' => 1, 'submitted_at' => now(), 'reviewed_at' => now()]);

        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.request-update', $account), [
            'reason' => 'Periodic', 'instructions' => 'Please update.', 'batch' => $supplierBatch->public_id,
        ])->assertSessionHasErrors('reason');

        expect(KycSubmission::query()->where('business_account_id', $account->id)->count())->toBe(1);
    });

    it('pins a Supplier round while it is still empty, and not once evidence is in it', function () {
        $batch = adminBatchLive($this->admin, 'supplier');
        $supplier = Supplier::factory()->kycPending()->create();

        $this->actingAs($this->admin, 'web')->post(route('admin.suppliers.kyc.batch.store', $supplier), ['batch' => $batch->public_id])
            ->assertSessionHasNoErrors();

        $round = SupplierKycSubmission::query()->where('supplier_id', $supplier->id)->sole();

        expect($round->kyc_batch_id)->toBe($batch->id);

        $round->documents()->create([
            'document_type' => adminBatchKey($batch, 'Passport'), 'slot_key' => 'photo_page', 'original_name' => 'p.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10, 'disk' => 'kyc', 'path' => 'x', 'checksum' => 'x', 'is_encrypted' => false,
        ]);

        $this->actingAs($this->admin, 'web')->post(route('admin.suppliers.kyc.batch.store', $supplier), ['batch' => $batch->public_id])
            ->assertSessionHasErrors('batch');
    });

    it('is refused to staff who may not set a Supplier up', function () {
        $batch = adminBatchLive($this->admin, 'supplier');
        $supplier = Supplier::factory()->kycPending()->create();

        $this->actingAs(User::factory()->staff()->create())
            ->post(route('admin.suppliers.kyc.batch.store', $supplier), ['batch' => $batch->public_id])
            ->assertForbidden();
    });
});

/**
 * A Partner who has given everything the live template asks for and submitted.
 *
 * @return array{0: KycSubmission, 1: User}
 */
function adminBatchSubmittedPartnerRound(KycBatch $batch, string $route = 'passport'): array
{
    $applicant = testBusinessAccount(AccountStatus::KycPending)->owner;
    $passport = adminBatchKey($batch, 'Passport');
    $tin = adminBatchKey($batch, 'TIN');

    test()->actingAs($applicant)->get(route('kyc.create'));
    test()->actingAs($applicant)->post(route('kyc.evidence.file'), ['requirement' => $passport, 'slot' => 'photo_page', 'file' => adminBatchImage()]);
    test()->actingAs($applicant)->post(route('kyc.evidence.value'), ['requirement' => $tin, 'slot' => 'tin_number', 'value' => '123456789012']);
    test()->actingAs($applicant)->post(route('kyc.submit'), ['confirm' => 1])->assertSessionHasNoErrors();

    return [KycSubmission::query()->latest('id')->firstOrFail(), $applicant];
}

/**
 * @return array<int, array<string, string>>
 */
function adminBatchVerdicts(KycBatch $batch, string $status = 'accepted'): array
{
    return [
        ['requirement' => adminBatchKey($batch, 'Passport'), 'slot' => 'photo_page', 'status' => $status],
        ['requirement' => adminBatchKey($batch, 'TIN'), 'slot' => 'tin_number', 'status' => $status],
    ];
}

describe('reviewing a Partner round group by group', function () {
    beforeEach(function () {
        $this->batch = adminBatchLive($this->admin);
        [$this->round, $this->applicant] = adminBatchSubmittedPartnerRound($this->batch);
    });

    it('shows the round as groups, with what stands between it and approval', function () {
        $this->actingAs($this->admin, 'web')->get(route('admin.kyc.show', $this->round))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/kyc/show')
                ->where('batch.groups.0.key', 'identity')
                ->where('batch.groups.0.options.1.slots.0.current.name', fn ($name) => str_ends_with($name, '.jpg'))
                ->where('batch.review.can_judge', true)
                ->has('batch.review.problems', 2)
                // A typed value is masked, never shown in full.
                ->where('batch.groups.1.options.0.slots.0.current.value_preview', fn ($value) => ! str_contains((string) $value, '123456789012')));
    });

    it('accepts and rejects evidence slot by slot, and a rejection must say what to fix', function () {
        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.evidence-review', $this->round), [
            'verdicts' => [['requirement' => adminBatchKey($this->batch, 'Passport'), 'slot' => 'photo_page', 'status' => 'rejected']],
        ])->assertSessionHasErrors('verdicts');

        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.evidence-review', $this->round), [
            'verdicts' => [
                ['requirement' => adminBatchKey($this->batch, 'Passport'), 'slot' => 'photo_page', 'status' => 'rejected', 'note' => 'The photo is blurred.'],
                ['requirement' => adminBatchKey($this->batch, 'TIN'), 'slot' => 'tin_number', 'status' => 'accepted'],
            ],
        ])->assertSessionHasNoErrors();

        expect(KycEvidenceReview::query()->count())->toBe(2);

        $this->actingAs($this->admin, 'web')->get(route('admin.kyc.show', $this->round))
            ->assertInertia(fn (Assert $page) => $page
                ->where('batch.groups.0.options.1.slots.0.current.verdict', 'rejected')
                ->where('batch.groups.0.options.1.slots.0.current.note', 'The photo is blurred.')
                ->where('batch.groups.1.options.0.slots.0.current.verdict', 'accepted'));
    });

    it('lands a set of verdicts whole or not at all', function () {
        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.evidence-review', $this->round), [
            'verdicts' => [
                ['requirement' => adminBatchKey($this->batch, 'TIN'), 'slot' => 'tin_number', 'status' => 'accepted'],
                // The NID back was never given.
                ['requirement' => adminBatchKey($this->batch, 'National ID'), 'slot' => 'back_side', 'status' => 'accepted'],
            ],
        ])->assertSessionHasErrors('verdicts');

        expect(KycEvidenceReview::query()->count())->toBe(0);
    });

    it('will not approve until every required group is satisfied and every item looked at', function () {
        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.decide', $this->round), ['outcome' => 'approve'])
            ->assertSessionHasErrors('outcome');

        // One accepted, one still unreviewed.
        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.evidence-review', $this->round), [
            'verdicts' => [adminBatchVerdicts($this->batch)[1]],
        ]);

        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.decide', $this->round), ['outcome' => 'approve'])
            ->assertSessionHasErrors('outcome');

        expect($this->round->refresh()->status)->toBe(KycStatus::Submitted);
    });

    it('will not approve while a required item stands rejected, even though it was reviewed', function () {
        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.evidence-review', $this->round), [
            'verdicts' => [
                [...adminBatchVerdicts($this->batch)[0], 'status' => 'rejected', 'note' => 'Blurred.'],
                adminBatchVerdicts($this->batch)[1],
            ],
        ]);

        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.decide', $this->round), ['outcome' => 'approve'])
            ->assertSessionHasErrors('outcome');
    });

    it('approves once everything owed has been accepted', function () {
        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.evidence-review', $this->round), ['verdicts' => adminBatchVerdicts($this->batch)]);

        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.decide', $this->round), ['outcome' => 'approve'])
            ->assertSessionHasNoErrors();

        expect($this->round->refresh()->status)->toBe(KycStatus::Approved);
    });

    it('can only be sent back with something rejected, and tells the applicant which items', function () {
        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.decide', $this->round), ['outcome' => 'resubmit', 'feedback' => 'Please redo.'])
            ->assertSessionHasErrors('outcome');

        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.evidence-review', $this->round), [
            'verdicts' => [
                [...adminBatchVerdicts($this->batch)[0], 'status' => 'rejected', 'note' => 'The photo is blurred.'],
                adminBatchVerdicts($this->batch)[1],
            ],
        ]);

        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.decide', $this->round), ['outcome' => 'resubmit'])
            ->assertSessionHasNoErrors();

        $review = $this->round->refresh()->reviews()->latest('id')->firstOrFail();

        expect($this->round->status)->toBe(KycStatus::ResubmissionRequired)
            ->and($review->user_visible_feedback)->toContain('Identity — Passport — Photo page: The photo is blurred.');
    });

    it('asks again only for what failed: the accepted evidence comes with the next round', function () {
        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.evidence-review', $this->round), [
            'verdicts' => [
                [...adminBatchVerdicts($this->batch)[0], 'status' => 'rejected', 'note' => 'Blurred.'],
                adminBatchVerdicts($this->batch)[1],
            ],
        ]);
        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.decide', $this->round), ['outcome' => 'resubmit']);

        $this->actingAs($this->applicant)->get(route('kyc.create'));

        $next = KycSubmission::query()->where('round', 2)->sole();

        expect($next->kyc_batch_version_id)->toBe($this->round->kyc_batch_version_id);

        $this->actingAs($this->applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page
                // The TIN was accepted, so it is already in and locked.
                ->where('batch.groups.1.options.0.slots.0.current.verdict', 'accepted')
                ->where('batch.groups.1.options.0.complete', true)
                // The passport photo was not, so it is empty and open.
                ->where('batch.groups.0.options.1.slots.0.current', null)
                ->where('batch.complete', false));

        // The accepted value cannot be replaced.
        $this->actingAs($this->applicant)
            ->post(route('kyc.evidence.value'), ['requirement' => adminBatchKey($this->batch, 'TIN'), 'slot' => 'tin_number', 'value' => '999'])
            ->assertSessionHasErrors('value');

        // Replacing just the passport photo completes it.
        $this->actingAs($this->applicant)->post(route('kyc.evidence.file'), [
            'requirement' => adminBatchKey($this->batch, 'Passport'), 'slot' => 'photo_page', 'file' => adminBatchImage('new.jpg'),
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page->where('batch.complete', true));

        // The reviewed round is untouched.
        expect($this->round->refresh()->documents()->count())->toBe(1);
    });

    it('refuses a review by someone who may not review, or on a round not awaiting one', function () {
        $this->actingAs(User::factory()->staff()->create())
            ->post(route('admin.kyc.evidence-review', $this->round), ['verdicts' => adminBatchVerdicts($this->batch)])
            ->assertForbidden();

        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.decide', $this->round), ['outcome' => 'reject', 'reason' => 'No.', 'feedback' => 'No.']);

        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.evidence-review', $this->round), ['verdicts' => adminBatchVerdicts($this->batch)])
            ->assertSessionHasErrors('verdicts');
    });

    it('writes the shape of a verdict to the audit log and never its note or the evidence', function () {
        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.evidence-review', $this->round), [
            'verdicts' => [[...adminBatchVerdicts($this->batch)[0], 'status' => 'rejected', 'note' => 'Secret instruction text.']],
        ]);

        $entry = DB::table('audit_logs')->where('action', 'kyc.evidence_reviewed')->sole();

        expect($entry->after)->not->toContain('Secret instruction text')
            ->and($entry->after)->toContain('photo_page');
    });
});

describe('reviewing a Supplier round group by group', function () {
    beforeEach(function () {
        $this->batch = adminBatchLive($this->admin, 'supplier');
        $this->supplier = supplierTestSignIn(Supplier::factory()->kycPending()->create());

        $this->get(route('supplier.kyc.create'));
        $this->post(route('supplier.kyc.evidence.file'), ['requirement' => adminBatchKey($this->batch, 'Passport'), 'slot' => 'photo_page', 'file' => adminBatchImage()]);
        $this->post(route('supplier.kyc.evidence.value'), ['requirement' => adminBatchKey($this->batch, 'TIN'), 'slot' => 'tin_number', 'value' => '123456789012']);
        $this->post(route('supplier.kyc.submit'), ['confirm' => 1])->assertSessionHasNoErrors();

        $this->round = $this->supplier->kycSubmissions()->firstOrFail();

    });

    it('shows the round as groups, for a reviewer only', function () {
        $this->actingAs($this->admin, 'web')->get(route('admin.suppliers.show', $this->supplier))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('batch.groups.0.key', 'identity')
                ->where('batch.review.can_judge', true)
                ->has('batch.review.problems', 2));
    });

    it('will not approve until everything owed has been accepted, then approves', function () {
        $this->actingAs($this->admin, 'web')->post(route('admin.suppliers.approval.store', $this->supplier))->assertSessionHasErrors('note');

        $this->actingAs($this->admin, 'web')->post(route('admin.suppliers.kyc.evidence-review', $this->supplier), ['verdicts' => adminBatchVerdicts($this->batch)])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin, 'web')->post(route('admin.suppliers.approval.store', $this->supplier))->assertSessionHasNoErrors();

        expect($this->round->refresh()->status->value)->toBe('approved');
    });

    it('sends only the rejected item back, in the same round, and tells the Supplier which', function () {
        $this->actingAs($this->admin, 'web')->post(route('admin.suppliers.kyc.correction.store', $this->supplier), ['feedback' => ''])
            ->assertSessionHasErrors('feedback');

        $this->actingAs($this->admin, 'web')->post(route('admin.suppliers.kyc.evidence-review', $this->supplier), [
            'verdicts' => [
                [...adminBatchVerdicts($this->batch)[0], 'status' => 'rejected', 'note' => 'Page cut off.'],
                adminBatchVerdicts($this->batch)[1],
            ],
        ]);

        $this->actingAs($this->admin, 'web')->post(route('admin.suppliers.kyc.correction.store', $this->supplier), ['feedback' => ''])
            ->assertSessionHasNoErrors();

        expect($this->round->refresh()->status->value)->toBe('correction_required')
            ->and($this->round->decision_note)->toContain('Identity — Passport — Photo page: Page cut off.');

        // Same round: the accepted TIN is locked, the rejected photo can be replaced.
        $this->post(route('supplier.kyc.evidence.value'), ['requirement' => adminBatchKey($this->batch, 'TIN'), 'slot' => 'tin_number', 'value' => '999'])
            ->assertSessionHasErrors('value');

        $this->post(route('supplier.kyc.evidence.file'), ['requirement' => adminBatchKey($this->batch, 'Passport'), 'slot' => 'photo_page', 'file' => adminBatchImage('new.jpg')])
            ->assertSessionHasNoErrors();

        // The first photo is kept, superseded, rather than deleted.
        expect(SupplierKycDocument::query()->count())->toBe(2)
            ->and(SupplierKycDocument::query()->whereNull('superseded_at')->count())->toBe(1);
    });

    it('refuses a verdict from staff who may not review Suppliers', function () {
        $this->actingAs(User::factory()->staff()->create())
            ->post(route('admin.suppliers.kyc.evidence-review', $this->supplier), ['verdicts' => adminBatchVerdicts($this->batch)])
            ->assertForbidden();
    });
});

describe('the example batch, and the hierarchy on screen', function () {
    it('seeds Standard KYC once: groups, options and slots, published and switched off', function () {
        $this->seed(StandardKycBatchSeeder::class);

        $batch = KycBatch::query()->where('key', 'standard_kyc')->sole();
        $identity = $batch->groups()->where('key', 'identity_verification')->sole();
        $business = $batch->groups()->where('key', 'business_verification')->sole();

        expect($batch->groups()->count())->toBe(2)
            ->and($batch->is_active)->toBeFalse()
            ->and($batch->is_default)->toBeFalse()
            ->and($batch->published_version)->toBe(1)
            ->and($identity->completion_rule->value)->toBe('any')
            ->and($business->completion_rule->value)->toBe('all')
            ->and($identity->items()->count())->toBe(3)
            ->and($business->items()->count())->toBe(2);

        // National ID's front and back are two slots of one option.
        $nid = KycDocumentType::query()->where('key', 'standard_kyc_national_id')->sole();

        expect($nid->slots()->pluck('label')->all())->toBe(['Front image', 'Back image'])
            ->and(KycDocumentType::query()->where('kyc_batch_id', $batch->id)->count())->toBe(5);
    });

    it('is idempotent: seeding again changes nothing', function () {
        $this->seed(StandardKycBatchSeeder::class);

        $before = [KycBatch::query()->count(), KycDocumentType::query()->count(), DB::table('kyc_requirement_slots')->count(), DB::table('kyc_batch_versions')->count()];

        $this->seed(StandardKycBatchSeeder::class);

        expect([KycBatch::query()->count(), KycDocumentType::query()->count(), DB::table('kyc_requirement_slots')->count(), DB::table('kyc_batch_versions')->count()])->toBe($before);
    });

    it('never touches a batch staff made, whatever its key', function () {
        // A staff-made batch with that name: nothing is created or changed.
        $theirs = KycBatch::create(['key' => 'their_own', 'name' => 'Standard KYC', 'audience' => 'account', 'is_active' => true]);

        $this->seed(StandardKycBatchSeeder::class);

        expect(KycBatch::query()->count())->toBe(1)
            ->and($theirs->refresh()->groups()->count())->toBe(0)
            ->and($theirs->is_active)->toBeTrue()
            ->and(KycDocumentType::query()->where('kyc_batch_id', $theirs->id)->count())->toBe(0);
    });

    it('reaches the list and the builder as a tree: groups, options, slots', function () {
        $this->seed(StandardKycBatchSeeder::class);
        $batch = KycBatch::query()->where('key', 'standard_kyc')->sole();

        $tree = fn (Assert $page, string $prefix) => $page
            ->where("{$prefix}.groups.0.name", 'Identity Verification')
            ->where("{$prefix}.groups.0.rule", 'any')
            ->has("{$prefix}.groups.0.options", 3)
            ->where("{$prefix}.groups.0.options.0.name", 'National ID')
            ->has("{$prefix}.groups.0.options.0.slots", 2)
            ->where("{$prefix}.groups.0.options.1.name", 'Passport')
            ->has("{$prefix}.groups.0.options.1.slots", 1)
            ->where("{$prefix}.groups.1.name", 'Business Verification')
            ->where("{$prefix}.groups.1.rule", 'all')
            ->has("{$prefix}.groups.1.options", 2);

        $this->actingAs($this->admin, 'web')->get(route('admin.kyc.batch-builder.index'))
            ->assertInertia(fn (Assert $page) => $tree($page, 'batches.0.outline'));

        $this->actingAs($this->admin, 'web')->get(route('admin.kyc.batch-builder.edit', $batch))
            ->assertInertia(fn (Assert $page) => $tree($page, 'outline')
                ->has('groups', 2)
                ->has('groups.0.requirements', 3)
                ->has('groups.0.requirements.0.slots', 2));
    });

    it('lets a Passport alone satisfy Identity while Business Verification is still owed', function () {
        $this->seed(StandardKycBatchSeeder::class);
        $batch = KycBatch::query()->where('key', 'standard_kyc')->sole();

        $this->actingAs($this->admin, 'web')->patch(route('admin.kyc.batch-builder.active', $batch), ['is_active' => 1])->assertSessionHasNoErrors();
        $this->actingAs($this->admin, 'web')->patch(route('admin.kyc.batch-builder.default', $batch), ['is_default' => 1])->assertSessionHasNoErrors();

        $applicant = testBusinessAccount(AccountStatus::KycPending)->owner;
        $this->actingAs($applicant)->get(route('kyc.create'));

        $this->actingAs($applicant)->post(route('kyc.evidence.file'), [
            'requirement' => 'standard_kyc_passport', 'slot' => 'bio_page_image_pdf', 'file' => adminBatchImage('passport.jpg'),
        ])->assertSessionHasNoErrors();

        $this->actingAs($applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page
                // Identity is done with the Passport alone: no NID, no Licence.
                ->where('batch.groups.0.satisfied', true)
                ->where('batch.groups.0.done', 1)
                ->where('batch.groups.0.options.0.chosen', false)
                ->where('batch.groups.0.options.1.chosen', true)
                // Business Verification is its own group and still owed, in full.
                ->where('batch.groups.1.satisfied', false)
                ->where('batch.groups.1.done', 0)
                ->where('batch.groups.1.needed', 2)
                ->where('batch.complete', false));

        $this->actingAs($applicant)->post(route('kyc.submit'), ['confirm' => 1])->assertSessionHasErrors('submission');

        // One Business option is not enough; both are.
        $this->actingAs($applicant)->post(route('kyc.evidence.file'), [
            'requirement' => 'standard_kyc_trade_licence', 'slot' => 'licence_pdf', 'file' => UploadedFile::fake()->create('licence.pdf', 50, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $this->actingAs($applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page->where('batch.groups.1.done', 1)->where('batch.complete', false));

        $this->actingAs($applicant)->post(route('kyc.evidence.file'), [
            'requirement' => 'standard_kyc_business_address', 'slot' => 'address_document', 'file' => adminBatchImage('address.jpg'),
        ])->assertSessionHasNoErrors();

        $this->actingAs($applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page->where('batch.groups.1.satisfied', true)->where('batch.complete', true));

        $this->actingAs($applicant)->post(route('kyc.submit'), ['confirm' => 1])->assertSessionHasNoErrors();
    });

    it('gives a batch made on the older criteria screen its place in the hierarchy', function () {
        $batch = KycBatch::create(['key' => 'older_screen', 'name' => 'Older screen', 'audience' => 'account', 'is_active' => false]);
        KycDocumentType::factory()->create(['kyc_batch_id' => $batch->id, 'audience' => 'account', 'key' => 'older_doc', 'name' => 'Older document']);

        // Opening it twice places it once.
        foreach ([1, 2] as $visit) {
            $this->actingAs($this->admin, 'web')->get(route('admin.kyc.batch-builder.edit', $batch))
                ->assertInertia(fn (Assert $page) => $page
                    ->has('groups', 1)
                    ->where('groups.0.name', 'Requirements')
                    ->where('groups.0.rule', 'all')
                    ->has('groups.0.requirements', 1)
                    ->where('groups.0.requirements.0.name', 'Older document')
                    ->has('groups.0.requirements.0.slots', 1));
        }

        expect($batch->groups()->count())->toBe(1)
            ->and(KycDocumentType::query()->where('key', 'older_doc')->sole()->slots()->count())->toBe(1);
    });

    it('places a criterion made on the older screen in a group straight away', function () {
        $batch = KycBatch::create(['key' => 'older_screen', 'name' => 'Older screen', 'audience' => 'account', 'is_active' => false]);

        app(ManageKycDocumentTypes::class)->create([
            'key' => 'made_there', 'name' => 'Made there', 'kyc_batch_id' => $batch->id, 'audience' => 'account',
            'is_required' => true, 'is_active' => true, 'accepted_mime_types' => ['image/jpeg'], 'max_size_kb' => 2048,
            'requires_file' => true, 'requires_value' => false,
        ], $this->admin);

        expect($batch->groups()->sole()->items()->count())->toBe(1);
    });

    it('is found from the Settings hub, and only by someone who may view batches', function () {
        $keys = fn (User $user) => collect($this->actingAs($user, 'web')->get(route('admin.settings'))->viewData('page')['props']['sections'])
            ->flatMap(fn ($section) => collect($section['items'])->pluck('key'))->all();

        expect($keys($this->admin))->toContain('kyc_batches');

        $viewer = User::factory()->staff()->create();
        $viewer->givePermissionTo('kyc_batch.view');

        expect($keys($viewer))->toContain('kyc_batches');

        $other = User::factory()->staff()->create();
        $other->givePermissionTo('kyc.view');

        expect($keys($other))->not->toContain('kyc_batches');
    });
});

describe('a form opened before any batch was switched on', function () {
    it('moves a Partner who has given nothing onto the default batch the next time they open it', function () {
        $account = testBusinessAccount(AccountStatus::KycPending);
        $applicant = $account->owner;
        // Something the older form asks for, so it has a snapshot to write.
        KycDocumentType::factory()->create(['audience' => 'account']);

        // Opened with no batch on: the older document-by-document form, with its snapshot.
        $this->actingAs($applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page->where('batch', null));

        $round = KycSubmission::query()->where('business_account_id', $account->id)->sole();

        expect($round->kyc_batch_version_id)->toBeNull()->and($round->requirements()->count())->toBeGreaterThan(0);

        adminBatchLive($this->admin);

        $this->actingAs($applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('batch.groups.0.key', 'identity')
                ->where('requirements', []));

        expect($round->refresh()->kyc_batch_version_id)->not->toBeNull()
            // The older snapshot described nothing the applicant did; the version is the round.
            ->and($round->requirements()->count())->toBe(0);
    });

    it('leaves a Partner who has already started on the older form', function () {
        $account = testBusinessAccount(AccountStatus::KycPending);
        $applicant = $account->owner;
        // Something the older form asks for, so it has a snapshot to write.
        KycDocumentType::factory()->create(['audience' => 'account']);

        $this->actingAs($applicant)->get(route('kyc.create'));

        $round = KycSubmission::query()->where('business_account_id', $account->id)->sole();
        $round->fields()->create(['kyc_document_type_id' => $round->requirements()->first()->kyc_document_type_id, 'value' => '123']);

        adminBatchLive($this->admin);

        $this->actingAs($applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page->where('batch', null));

        expect($round->refresh()->kyc_batch_version_id)->toBeNull();
    });

    it('leaves a later round on the form it was opened for', function () {
        $account = testBusinessAccount(AccountStatus::KycPending);
        $round = KycSubmission::create(['business_account_id' => $account->id, 'status' => KycStatus::Draft, 'round' => 2]);

        adminBatchLive($this->admin);

        $this->actingAs($account->owner)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page->where('batch', null));

        expect($round->refresh()->kyc_batch_version_id)->toBeNull();
    });

    it('moves a Supplier who has given nothing onto the default batch, and leaves one who has been sent', function () {
        $supplier = Supplier::factory()->kycPending()->create();

        $this->actingAs($supplier, 'supplier')->get(route('supplier.kyc.create'))
            ->assertInertia(fn (Assert $page) => $page->where('batch', null));

        adminBatchLive($this->admin, 'supplier');

        $this->actingAs($supplier, 'supplier')->get(route('supplier.kyc.create'))
            ->assertInertia(fn (Assert $page) => $page->where('batch.groups.0.key', 'identity'));

        // One that was sent under the older form keeps it.
        $other = Supplier::factory()->kycPending()->create();
        $sent = SupplierKycSubmission::create(['supplier_id' => $other->id, 'round' => 1, 'status' => 'correction_required', 'submitted_at' => now()]);

        $this->actingAs($other, 'supplier')->get(route('supplier.kyc.create'))
            ->assertInertia(fn (Assert $page) => $page->where('batch', null));

        expect($sent->refresh()->kyc_batch_version_id)->toBeNull();
    });

    it('opens a re-verification on the default batch unless staff narrowed the documents', function () {
        $default = adminBatchLive($this->admin);

        foreach ([null, ['narrow' => true]] as $narrowed) {
            $account = testBusinessAccount(AccountStatus::Active);
            KycSubmission::create(['business_account_id' => $account->id, 'status' => KycStatus::Approved, 'round' => 1, 'submitted_at' => now(), 'reviewed_at' => now()]);

            $payload = ['reason' => 'Periodic', 'instructions' => 'Please update.'];

            if ($narrowed !== null) {
                $payload['document_type_ids'] = [KycDocumentType::factory()->create(['audience' => 'account'])->public_id];
            }

            $this->actingAs($this->admin, 'web')->post(route('admin.kyc.request-update', $account), $payload)->assertSessionHasNoErrors();

            $round = KycSubmission::query()->where('business_account_id', $account->id)->orderByDesc('round')->firstOrFail();

            // Absent means "everything that applies": the default batch. A narrowed
            // list is a different request and stays on the documents named.
            expect($round->kyc_batch_id)->toBe($narrowed === null ? $default->id : null);
        }
    });

    it('says which applicants still see the older form because no batch is their default', function () {
        $uncovered = fn () => $this->actingAs($this->admin, 'web')->get(route('admin.kyc.batch-builder.index'))->viewData('page')['props']['uncovered'];

        expect($uncovered())->toBe(['account', 'supplier']);

        adminBatchLive($this->admin, 'account');

        expect($uncovered())->toBe(['supplier']);

        // One batch for both serves the other audience too.
        $both = adminBatchBuilt($this->admin, 'both', 'Both audiences');
        $this->actingAs($this->admin, 'web')->patch(route('admin.kyc.batch-builder.active', $both), ['is_active' => 1]);
        $this->actingAs($this->admin, 'web')->patch(route('admin.kyc.batch-builder.default', $both), ['is_default' => 1]);

        expect($uncovered())->toBe([]);
    });
});
