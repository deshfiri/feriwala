<?php

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Kyc\Actions\CaptureRoundRequirements;
use App\Domain\Kyc\Actions\ManageKycBatches;
use App\Domain\Kyc\Actions\OpenKycDraft;
use App\Domain\Kyc\Actions\RequestKycUpdate;
use App\Domain\Kyc\Actions\SubmitKyc;
use App\Domain\Kyc\Enums\KycAudience;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycBatch;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Supplier\Actions\OpenSupplierKycRound;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Verification batches (§7.2).
 *
 * An administrator groups criteria into batches and decides who each batch is
 * asked of; an applicant completes every batch that applies to them in one
 * submission, with no round numbers anywhere.
 *
 * Helpers are prefixed because Pest loads every test file into one global
 * function namespace.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->admin = testPlatformStaff(PlatformRole::SuperAdmin);
});

/**
 * @param  array<int, array{package?: string|null, country?: string|null}>  $rules
 */
function kycBatchTestBatch(string $key, string $name, array $rules = [], array $state = []): KycBatch
{
    $batch = KycBatch::create([
        'key' => $key,
        'name' => $name,
        'audience' => KycAudience::Account,
        'sort_order' => KycBatch::query()->count(),
        ...$state,
    ]);

    foreach ($rules as $rule) {
        $batch->scopes()->create([
            'package_public_id' => $rule['package'] ?? null,
            'country_code' => $rule['country'] ?? null,
        ]);
    }

    return $batch;
}

function kycBatchTestType(KycBatch $batch, string $key, array $state = []): KycDocumentType
{
    return KycDocumentType::factory()->create([
        'kyc_batch_id' => $batch->id,
        'audience' => $batch->audience,
        'key' => $key,
        'name' => ucfirst(str_replace('_', ' ', $key)),
        ...$state,
    ]);
}

/** Somebody who configures the catalogue but is not Super Admin, so the policy is observable. */
function kycBatchTestConfigurer(): User
{
    $user = User::factory()->staff()->create();
    $user->givePermissionTo(PermissionCatalogue::name(PermissionModule::Kyc, PermissionAction::ManageSettings));

    return $user;
}

/**
 * @return array<string, mixed>
 */
function kycBatchTestPayload(array $overrides = []): array
{
    return [
        'key' => 'identity',
        'audience' => 'account',
        'name' => 'Identity verification',
        'description' => 'Prove who you are.',
        'is_active' => '1',
        ...$overrides,
    ];
}

describe('managing batches', function () {
    it('lets an administrator create a batch with the rules saying who is asked', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.kyc.batches.store'), kycBatchTestPayload([
                'rules' => [['package' => '', 'country' => 'BD'], ['package' => '', 'country' => 'IN']],
            ]))
            ->assertSessionHasNoErrors();

        $batch = KycBatch::query()->where('key', 'identity')->sole();

        expect($batch->name)->toBe('Identity verification')
            ->and($batch->audience)->toBe(KycAudience::Account)
            ->and($batch->scopes->pluck('country_code')->sort()->values()->all())->toBe(['BD', 'IN']);
    });

    it('lets there be as many batches as the administrator likes, none of them built in', function () {
        foreach (['identity', 'business', 'address'] as $key) {
            $this->actingAs($this->admin)->post(route('admin.kyc.batches.store'), kycBatchTestPayload(['key' => $key, 'name' => ucfirst($key)]));
        }

        expect(KycBatch::query()->whereIn('key', ['identity', 'business', 'address'])->count())->toBe(3);
    });

    it('refuses a duplicate key and a rule that names nothing', function () {
        kycBatchTestBatch('identity', 'Identity');

        $this->actingAs($this->admin)
            ->post(route('admin.kyc.batches.store'), kycBatchTestPayload())
            ->assertSessionHasErrors('key');

        $this->actingAs($this->admin)
            ->post(route('admin.kyc.batches.store'), kycBatchTestPayload(['key' => 'other', 'rules' => [['package' => '', 'country' => '']]]))
            ->assertSessionHasErrors('rules.0.package');
    });

    it('is closed to anyone without the KYC settings permission', function () {
        $owner = testBusinessAccount(AccountStatus::Active)->owner;

        $this->actingAs($owner)->post(route('admin.kyc.batches.store'), kycBatchTestPayload())->assertForbidden();
        $this->actingAs(testPlatformStaff(PlatformRole::SmsManager))->post(route('admin.kyc.batches.store'), kycBatchTestPayload())->assertForbidden();

        expect(KycBatch::query()->where('key', 'identity')->exists())->toBeFalse();

        $this->actingAs(kycBatchTestConfigurer())->post(route('admin.kyc.batches.store'), kycBatchTestPayload())->assertSessionHasNoErrors();
    });

    it('carries a batch\'s criteria with it when its audience changes', function () {
        $batch = kycBatchTestBatch('identity', 'Identity');
        $type = kycBatchTestType($batch, 'nid');

        $this->actingAs($this->admin)
            ->patch(route('admin.kyc.batches.update', $batch->public_id), kycBatchTestPayload(['audience' => 'both']))
            ->assertSessionHasNoErrors();

        expect($batch->refresh()->audience)->toBe(KycAudience::Both)
            ->and($type->refresh()->audience)->toBe(KycAudience::Both);
    });

    it('replaces the rules wholesale on save', function () {
        $batch = kycBatchTestBatch('identity', 'Identity', [['country' => 'BD']]);

        $this->actingAs($this->admin)
            ->patch(route('admin.kyc.batches.update', $batch->public_id), kycBatchTestPayload(['rules' => [['package' => '', 'country' => 'IN']]]));

        expect($batch->scopes()->pluck('country_code')->all())->toBe(['IN']);

        $this->actingAs($this->admin)->patch(route('admin.kyc.batches.update', $batch->public_id), kycBatchTestPayload());

        expect($batch->scopes()->count())->toBe(0);
    });

    it('pauses and resumes a batch', function () {
        $batch = kycBatchTestBatch('identity', 'Identity');

        $this->actingAs($this->admin)->patch(route('admin.kyc.batches.active', $batch->public_id), ['is_active' => false]);
        expect($batch->refresh()->is_active)->toBeFalse();

        $this->actingAs($this->admin)->patch(route('admin.kyc.batches.active', $batch->public_id), ['is_active' => true]);
        expect($batch->refresh()->is_active)->toBeTrue();
    });

    it('archives a batch with its criteria, and an archived batch cannot be edited or brought back', function () {
        $batch = kycBatchTestBatch('identity', 'Identity');
        $type = kycBatchTestType($batch, 'nid');

        $this->actingAs($this->admin)->post(route('admin.kyc.batches.archive', $batch->public_id));

        expect($batch->refresh()->isArchived())->toBeTrue()
            ->and($batch->is_active)->toBeFalse()
            ->and($type->refresh()->isArchived())->toBeTrue();

        $this->actingAs($this->admin)
            ->patch(route('admin.kyc.batches.update', $batch->public_id), kycBatchTestPayload(['name' => 'Renamed']))
            ->assertSessionHasErrors('name');
        $this->actingAs($this->admin)
            ->patch(route('admin.kyc.batches.active', $batch->public_id), ['is_active' => true])
            ->assertSessionHasErrors('is_active');
    });

    it('deletes only a batch that holds nothing and has been used by nobody', function () {
        $holding = kycBatchTestBatch('identity', 'Identity');
        kycBatchTestType($holding, 'nid');
        $empty = kycBatchTestBatch('spare', 'Spare');

        $this->actingAs($this->admin)->delete(route('admin.kyc.batches.destroy', $holding->public_id))->assertSessionHasErrors('batch');
        expect(KycBatch::query()->whereKey($holding->id)->exists())->toBeTrue();

        $this->actingAs($this->admin)->delete(route('admin.kyc.batches.destroy', $empty->public_id))->assertSessionHasNoErrors();
        expect(KycBatch::query()->whereKey($empty->id)->exists())->toBeFalse();
    });

    it('orders batches by hand', function () {
        $first = kycBatchTestBatch('a', 'A');
        $second = kycBatchTestBatch('b', 'B');

        $this->actingAs($this->admin)->post(route('admin.kyc.batches.reorder'), ['order' => [$second->public_id, $first->public_id]]);

        expect($second->refresh()->sort_order)->toBeLessThan($first->refresh()->sort_order);
    });
});

describe('criteria in batches', function () {
    function kycBatchTestCriterion(array $overrides = []): array
    {
        return [
            'key' => 'national_id',
            'name' => 'National ID',
            'is_required' => '1',
            'is_active' => '1',
            'requires_file' => '1',
            'requires_value' => '0',
            'accepted_mime_types' => ['image/jpeg'],
            'max_size_kb' => 2048,
            ...$overrides,
        ];
    }

    it('puts a new criterion in the chosen batch and asks it of the same people', function () {
        $batch = kycBatchTestBatch('both_kinds', 'Both', state: ['audience' => KycAudience::Both]);

        $this->actingAs($this->admin)
            ->post(route('admin.kyc.document-types.store'), kycBatchTestCriterion(['batch' => $batch->public_id]))
            ->assertSessionHasNoErrors();

        $type = KycDocumentType::query()->where('key', 'national_id')->sole();

        expect($type->kyc_batch_id)->toBe($batch->id)
            ->and($type->audience)->toBe(KycAudience::Both);
    });

    it('refuses a batch that is archived or does not exist', function () {
        $archived = kycBatchTestBatch('old', 'Old', state: ['archived_at' => now(), 'is_active' => false]);

        $this->actingAs($this->admin)
            ->post(route('admin.kyc.document-types.store'), kycBatchTestCriterion(['batch' => $archived->public_id]))
            ->assertSessionHasErrors('batch');
        $this->actingAs($this->admin)
            ->post(route('admin.kyc.document-types.store'), kycBatchTestCriterion(['batch' => '01JNOSUCHBATCH0000000000000']))
            ->assertSessionHasErrors('batch');
    });

    it('shows the batches and which one each criterion sits in', function () {
        $batch = kycBatchTestBatch('identity', 'Identity', [['country' => 'BD']]);
        kycBatchTestType($batch, 'nid');

        $this->actingAs($this->admin)
            ->get(route('admin.kyc.document-types.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('batches.0.key', 'identity')
                ->where('batches.0.criteria_count', 1)
                ->where('batches.0.rules.0.country', 'BD')
                ->where('types.0.batch', $batch->public_id),
            );
    });
});

describe('who is asked', function () {
    it('asks everyone for a batch with no rules', function () {
        $batch = kycBatchTestBatch('identity', 'Identity');
        kycBatchTestType($batch, 'nid');

        expect(app(CaptureRoundRequirements::class)->applicableFor('pkg-1', 'BD'))->toHaveCount(1)
            ->and(app(CaptureRoundRequirements::class)->applicableFor(null, null))->toHaveCount(1);
    });

    it('asks only whoever matches one of the batch\'s rules', function () {
        $batch = kycBatchTestBatch('local', 'Local', [['country' => 'BD'], ['package' => 'pkg-gold', 'country' => 'IN']]);
        kycBatchTestType($batch, 'licence');

        $asked = fn (?string $package, ?string $country) => app(CaptureRoundRequirements::class)->applicableFor($package, $country)->count();

        expect($asked('pkg-basic', 'BD'))->toBe(1)       // the country rule
            ->and($asked('pkg-gold', 'IN'))->toBe(1)      // package and country together
            ->and($asked('pkg-basic', 'IN'))->toBe(0)     // right country, wrong package
            ->and($asked('pkg-gold', 'US'))->toBe(0)      // right package, wrong country
            ->and($asked(null, null))->toBe(0);
    });

    it('does not ask for a paused or archived batch', function () {
        kycBatchTestType(kycBatchTestBatch('paused', 'Paused', state: ['is_active' => false]), 'one');
        kycBatchTestType(kycBatchTestBatch('gone', 'Gone', state: ['archived_at' => now(), 'is_active' => false]), 'two');

        expect(app(CaptureRoundRequirements::class)->applicableFor('pkg-1', 'BD'))->toHaveCount(0);
    });

    it('still asks for a criterion that is in no batch', function () {
        KycDocumentType::factory()->create(['key' => 'legacy', 'audience' => KycAudience::Account]);

        expect(app(CaptureRoundRequirements::class)->applicableFor('pkg-1', 'BD'))->toHaveCount(1);
    });
});

describe('one submission, however many batches', function () {
    beforeEach(function () {
        $this->account = testBusinessAccount(AccountStatus::KycPending);
        $this->applicant = $this->account->owner;
        $this->applicant->forceFill(['country' => 'BD'])->save();

        $this->identity = kycBatchTestBatch('identity', 'Identity', state: ['description' => 'Prove who you are.']);
        $this->business = kycBatchTestBatch('business', 'Business');

        $this->nid = kycBatchTestType($this->identity, 'nid', ['sort_order' => 2]);
        $this->selfie = kycBatchTestType($this->identity, 'selfie', ['sort_order' => 1]);
        $this->licence = kycBatchTestType($this->business, 'licence', ['sort_order' => 1]);
    });

    it('lays every batch out on one form, in the administrator\'s order', function () {
        $this->actingAs($this->applicant)
            ->get(route('kyc.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('requirements', 3)
                ->where('requirements.0.key', 'selfie')
                ->where('requirements.0.batch.name', 'Identity')
                ->where('requirements.0.batch.description', 'Prove who you are.')
                ->where('requirements.1.key', 'nid')
                ->where('requirements.2.key', 'licence')
                ->where('requirements.2.batch.name', 'Business'),
            );

        expect(KycSubmission::query()->where('business_account_id', $this->account->id)->count())->toBe(1);
    });

    it('carries no round number to the applicant', function () {
        $this->actingAs($this->applicant)
            ->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page->missing('submission.round'));
    });

    it('needs every required criterion in every batch before it can be submitted', function () {
        $submission = app(OpenKycDraft::class)->handle($this->account);

        $missing = app(SubmitKyc::class)->missingRequirements($submission, null, 'BD');

        expect($missing)->toHaveCount(3);
    });

    it('keeps the batch a submission was asked under, whatever the batch is called later', function () {
        $submission = app(OpenKycDraft::class)->handle($this->account);

        $this->identity->update(['name' => 'Renamed later']);
        $this->nid->update(['kyc_batch_id' => $this->business->id]);

        $requirement = $submission->requirements()->where('key', 'nid')->sole();

        expect($requirement->batch_name)->toBe('Identity')
            ->and($requirement->batch_key)->toBe('identity')
            ->and($requirement->kyc_batch_id)->toBe($this->identity->id);
    });

    it('asks again for only the batches the administrator picks', function () {
        $account = testBusinessAccount();
        KycSubmission::create(['business_account_id' => $account->id, 'status' => KycStatus::Approved, 'round' => 1, 'submitted_at' => now()->subYear(), 'reviewed_at' => now()->subYear()]);
        $account->owner->forceFill(['country' => 'BD'])->save();

        $officer = testPlatformStaff(PlatformRole::KycManager);

        // Asking for the Business batch means asking for what it holds, nothing else.
        $submission = app(RequestKycUpdate::class)->handle(
            $account,
            $officer,
            'Licence renewal.',
            'Please renew your trade licence.',
            documentTypeIds: $this->business->types()->pluck('public_id')->all(),
        );

        expect($submission->requirements()->pluck('key')->all())->toBe(['licence'])
            ->and($submission->requirements()->sole()->batch_name)->toBe('Business');
    });

    it('offers the re-verification dialog the batches an account is subject to', function () {
        $account = testBusinessAccount();
        $account->owner->forceFill(['country' => 'BD'])->save();
        KycSubmission::create(['business_account_id' => $account->id, 'status' => KycStatus::Approved, 'round' => 1]);

        $this->actingAs($this->admin)
            ->get(route('admin.accounts.show', $account->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('document_types.0.batch.name', 'Identity')
                ->where('document_types.2.batch.name', 'Business'),
            );
    });
});

describe('Suppliers', function () {
    it('is asked for the active batches marked for it, and the form records which', function () {
        $batch = kycBatchTestBatch('supplier_docs', 'Supplier documents', state: ['audience' => KycAudience::Supplier]);
        kycBatchTestType($batch, 'gst', ['sort_order' => 1]);

        $paused = kycBatchTestBatch('supplier_paused', 'Paused', state: ['audience' => KycAudience::Supplier, 'is_active' => false]);
        kycBatchTestType($paused, 'hidden');

        $supplier = Supplier::factory()->kycPending()->create();

        $round = app(OpenSupplierKycRound::class)->handle($supplier);

        $requirement = $round->requirements()->sole();

        expect($requirement->key)->toBe('gst')
            ->and($requirement->batch_name)->toBe('Supplier documents');
    });
});

describe('the batch rules', function () {
    it('match a package and country independently, and a null dimension matches anything', function () {
        $batch = kycBatchTestBatch('mixed', 'Mixed', [['package' => 'pkg-a'], ['country' => 'BD']]);
        $scopes = $batch->scopes;

        expect($batch->appliesTo('pkg-a', 'US', $scopes))->toBeTrue()
            ->and($batch->appliesTo('pkg-b', 'BD', $scopes))->toBeTrue()
            ->and($batch->appliesTo('pkg-b', 'US', $scopes))->toBeFalse();
    });

    it('is created through the action with the same audit trail as any other KYC configuration change', function () {
        $batch = app(ManageKycBatches::class)->create(
            ['key' => 'audited', 'audience' => 'account', 'name' => 'Audited'],
            [['country' => 'BD']],
            $this->admin,
        );

        expect($batch->scopes()->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'kyc.batch_created')->count())->toBe(1);
    });
});
