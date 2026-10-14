<?php

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycBatch;
use App\Domain\Kyc\Models\KycDocument;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycEvidenceReview;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierKycDocument;
use App\Models\User;
use App\Notifications\Account\KycApproved;
use App\Notifications\Account\KycRejected;
use App\Notifications\Account\KycResubmissionRequested;
use App\Notifications\Staff\WorkItemSubmitted;
use App\Notifications\Supplier\SupplierApproved;
use App\Notifications\Supplier\SupplierKycCorrectionRequested;
use App\Notifications\Supplier\SupplierRejected;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Closure of the KYC batch work (D30, commit 5): dedicated permissions, the
 * notifications that tell people what happened without telling them what was in
 * it, the live selfie, and the controls around files, values and guards.
 */

const KYC_SEC_TIN = '123456789012';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('kyc');
    Notification::fake();

    $this->admin = testPlatformStaff(PlatformRole::SuperAdmin);
});

/**
 * What a real request starts from.
 *
 * Every real request is a fresh process whose default guard is the configured one;
 * `auth:supplier` then switches it for that request only. A test drives many
 * requests in one process, so the switch would otherwise leak into the next one —
 * which no browser visit does. This puts the default back, and touches neither
 * session: the staff and the Supplier user both stay signed in on their own guards.
 */
function kycSecFresh(): void
{
    // `shouldUse()` rewrites `auth.defaults.guard` itself, so the configured value
    // cannot be read back after a Supplier visit; `web` is what config/auth.php sets.
    Auth::shouldUse('web');
}

function kycSecStaff(string ...$permissions): User
{
    $user = User::factory()->staff()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function kycSecPermission(PermissionModule $module, PermissionAction $action): string
{
    return PermissionCatalogue::name($module, $action);
}

/** A live batch: any one of NID (front, back) or passport, a TIN, and a selfie as asked. */
function kycSecBatch(User $admin, string $audience = 'account', string $selfie = 'disabled', bool $selfieUpload = false): KycBatch
{
    $image = ['evidence' => 'image', 'required' => true, 'mimes' => ['image/jpeg', 'image/png'], 'max_kb' => 2048];

    test()->actingAs($admin, 'web')->post(route('admin.kyc.batch-builder.store'), [
        'name' => 'Secure '.$audience, 'audience' => $audience, 'selfie_mode' => $selfie, 'selfie_allows_upload' => (int) $selfieUpload,
    ])->assertSessionHasNoErrors();

    $batch = KycBatch::query()->latest('id')->firstOrFail();

    test()->actingAs($admin, 'web')->put(route('admin.kyc.batch-builder.structure', $batch), ['groups' => [
        ['name' => 'Identity', 'rule' => 'any', 'required' => true, 'requirements' => [
            ['name' => 'National ID', 'required' => true, 'slots' => [['label' => 'Front side', ...$image], ['label' => 'Back side', ...$image]]],
            ['name' => 'Passport', 'required' => true, 'slots' => [['label' => 'Photo page', ...$image]]],
        ]],
        ['name' => 'Business', 'rule' => 'all', 'required' => true, 'requirements' => [
            ['name' => 'TIN', 'required' => true, 'slots' => [['label' => 'TIN number', 'evidence' => 'value', 'required' => true, 'sensitive' => true]]],
        ]],
    ]])->assertSessionHasNoErrors();

    test()->actingAs($admin, 'web')->patch(route('admin.kyc.batch-builder.active', $batch), ['is_active' => 1])->assertSessionHasNoErrors();
    test()->actingAs($admin, 'web')->patch(route('admin.kyc.batch-builder.default', $batch), ['is_default' => 1])->assertSessionHasNoErrors();

    return $batch->refresh();
}

function kycSecKey(KycBatch $batch, string $name): string
{
    return (string) KycDocumentType::query()->where('kyc_batch_id', $batch->id)->where('name', $name)->value('key');
}

function kycSecImage(string $name = 'photo.jpg', int $kb = 100): UploadedFile
{
    return UploadedFile::fake()->image($name)->size($kb);
}

/** @return array{0: KycSubmission, 1: User} a Partner who has given a passport and a TIN, and submitted */
function kycSecSubmittedPartner(KycBatch $batch, bool $selfie = false): array
{
    $applicant = testBusinessAccount(AccountStatus::KycPending)->owner;

    test()->actingAs($applicant)->get(route('kyc.create'));
    test()->actingAs($applicant)->post(route('kyc.evidence.file'), ['requirement' => kycSecKey($batch, 'Passport'), 'slot' => 'photo_page', 'file' => kycSecImage('passport-secret-name.jpg')]);
    test()->actingAs($applicant)->post(route('kyc.evidence.value'), ['requirement' => kycSecKey($batch, 'TIN'), 'slot' => 'tin_number', 'value' => KYC_SEC_TIN]);

    if ($selfie) {
        test()->actingAs($applicant)->post(route('kyc.evidence.file'), ['requirement' => '__selfie', 'slot' => 'capture', 'file' => kycSecImage('s.jpg'), 'captured_live' => 1]);
    }

    test()->actingAs($applicant)->post(route('kyc.submit'), ['confirm' => 1])->assertSessionHasNoErrors();

    return [KycSubmission::query()->latest('id')->firstOrFail(), $applicant];
}

/**
 * @return array<int, array<string, string>>
 */
function kycSecVerdicts(KycBatch $batch, bool $selfie = false): array
{
    $verdicts = [
        ['requirement' => kycSecKey($batch, 'Passport'), 'slot' => 'photo_page', 'status' => 'accepted'],
        ['requirement' => kycSecKey($batch, 'TIN'), 'slot' => 'tin_number', 'status' => 'accepted'],
    ];

    if ($selfie) {
        $verdicts[] = ['requirement' => '__selfie', 'slot' => 'capture', 'status' => 'accepted'];
    }

    return $verdicts;
}

describe('dedicated permissions', function () {
    it('lets someone who may only view see the templates, and nothing more', function () {
        $batch = kycSecBatch($this->admin);
        $viewer = kycSecStaff(kycSecPermission(PermissionModule::KycBatch, PermissionAction::View));

        $this->actingAs($viewer, 'web')->get(route('admin.kyc.batch-builder.index'))->assertOk();
        $this->actingAs($viewer, 'web')->get(route('admin.kyc.batch-builder.edit', $batch))->assertOk();
        $this->actingAs($viewer, 'web')->get(route('admin.kyc.batch-builder.preview', $batch))->assertOk();

        $this->actingAs($viewer, 'web')->post(route('admin.kyc.batch-builder.store'), ['name' => 'X', 'audience' => 'account', 'selfie_mode' => 'disabled', 'selfie_allows_upload' => 0])->assertForbidden();
        $this->actingAs($viewer, 'web')->post(route('admin.kyc.batch-builder.clone', $batch))->assertForbidden();
        $this->actingAs($viewer, 'web')->patch(route('admin.kyc.batch-builder.update', $batch), ['name' => 'Y', 'audience' => 'account', 'selfie_mode' => 'disabled', 'selfie_allows_upload' => 0])->assertForbidden();
        $this->actingAs($viewer, 'web')->post(route('admin.kyc.batch-builder.publish', $batch))->assertForbidden();
    });

    it('keeps building, switching on and viewing as three separate permissions', function () {
        $batch = kycSecBatch($this->admin);
        $builder = kycSecStaff(
            kycSecPermission(PermissionModule::KycBatch, PermissionAction::View),
            kycSecPermission(PermissionModule::KycBatch, PermissionAction::Create),
            kycSecPermission(PermissionModule::KycBatch, PermissionAction::Edit),
        );
        $operator = kycSecStaff(
            kycSecPermission(PermissionModule::KycBatch, PermissionAction::View),
            kycSecPermission(PermissionModule::KycBatch, PermissionAction::Activate),
        );

        // A builder can change it but cannot take it live or off.
        $this->actingAs($builder, 'web')->patch(route('admin.kyc.batch-builder.update', $batch), ['name' => 'Renamed', 'audience' => 'account', 'selfie_mode' => 'disabled', 'selfie_allows_upload' => 0])
            ->assertSessionHasNoErrors();
        $this->actingAs($builder, 'web')->post(route('admin.kyc.batch-builder.clone', $batch))->assertRedirect();
        $this->actingAs($builder, 'web')->patch(route('admin.kyc.batch-builder.active', $batch), ['is_active' => 0])->assertForbidden();
        $this->actingAs($builder, 'web')->patch(route('admin.kyc.batch-builder.default', $batch), ['is_default' => 0])->assertForbidden();

        // An operator can take it live or off but cannot change what it asks.
        $this->actingAs($operator, 'web')->patch(route('admin.kyc.batch-builder.active', $batch), ['is_active' => 0])->assertSessionHasNoErrors();
        $this->actingAs($operator, 'web')->put(route('admin.kyc.batch-builder.structure', $batch), ['groups' => []])->assertForbidden();

        expect($batch->refresh()->name)->toBe('Renamed')->and($batch->is_active)->toBeFalse();
    });

    it('needs the assign permission to choose a batch for a round, beyond asking for the update', function () {
        $batch = kycSecBatch($this->admin);
        $account = testBusinessAccount(AccountStatus::Active);
        KycSubmission::create(['business_account_id' => $account->id, 'status' => KycStatus::Approved, 'round' => 1, 'submitted_at' => now(), 'reviewed_at' => now()]);

        $verifier = kycSecStaff(kycSecPermission(PermissionModule::Kyc, PermissionAction::Verify));
        $payload = ['reason' => 'Periodic', 'instructions' => 'Please update.', 'batch' => $batch->public_id];

        $this->actingAs($verifier, 'web')->post(route('admin.kyc.request-update', $account), $payload)->assertForbidden();

        $verifier->givePermissionTo(kycSecPermission(PermissionModule::KycBatch, PermissionAction::Assign));

        $this->actingAs($verifier, 'web')->post(route('admin.kyc.request-update', $account), $payload)->assertSessionHasNoErrors();

        expect(KycSubmission::query()->where('business_account_id', $account->id)->count())->toBe(2);
    });

    it('needs the review permission as well as the right to review the round', function () {
        $batch = kycSecBatch($this->admin);
        [$round] = kycSecSubmittedPartner($batch);
        $payload = ['verdicts' => kycSecVerdicts($batch)];

        $approverOnly = kycSecStaff(kycSecPermission(PermissionModule::Kyc, PermissionAction::View), kycSecPermission(PermissionModule::Kyc, PermissionAction::Approve));
        $batchReviewerOnly = kycSecStaff(kycSecPermission(PermissionModule::Kyc, PermissionAction::View), kycSecPermission(PermissionModule::KycBatch, PermissionAction::Review));
        $both = kycSecStaff(
            kycSecPermission(PermissionModule::Kyc, PermissionAction::View),
            kycSecPermission(PermissionModule::Kyc, PermissionAction::Approve),
            kycSecPermission(PermissionModule::KycBatch, PermissionAction::Review),
        );

        $this->actingAs($approverOnly, 'web')->post(route('admin.kyc.evidence-review', $round), $payload)->assertForbidden();
        $this->actingAs($batchReviewerOnly, 'web')->post(route('admin.kyc.evidence-review', $round), $payload)->assertForbidden();

        expect(KycEvidenceReview::query()->count())->toBe(0);

        $this->actingAs($both, 'web')->post(route('admin.kyc.evidence-review', $round), $payload)->assertSessionHasNoErrors();

        expect(KycEvidenceReview::query()->count())->toBe(2);
    });

    it('seeds the roles with what each one does', function () {
        $all = array_map(
            fn (PermissionAction $action) => PermissionCatalogue::name(PermissionModule::KycBatch, $action),
            [PermissionAction::View, PermissionAction::Create, PermissionAction::Edit, PermissionAction::Activate, PermissionAction::Assign, PermissionAction::Review, PermissionAction::ViewSensitiveData],
        );

        expect(PermissionCatalogue::all())->toContain(...$all)
            ->and(PlatformRole::KycManager->permissions())->toContain(...$all)
            ->and(PlatformRole::SuperAdmin->permissions())->toContain(...$all)
            // Oversight only.
            ->and(array_values(array_filter(PlatformRole::Admin->permissions(), fn ($p) => str_starts_with($p, 'kyc_batch.'))))->toBe(['kyc_batch.view'])
            // Opens and grades a Supplier's round; builds and switches nothing.
            ->and(array_values(array_filter(PlatformRole::SupplierManager->permissions(), fn ($p) => str_starts_with($p, 'kyc_batch.'))))
            ->toEqualCanonicalizing(['kyc_batch.view', 'kyc_batch.assign', 'kyc_batch.review'])
            ->and(array_filter(PlatformRole::ReportViewer->permissions(), fn ($p) => str_starts_with($p, 'kyc_batch.')))->toBe([]);

        // The seeder put them in the database too.
        $manager = testPlatformStaff(PlatformRole::KycManager);

        expect($manager->can('kyc_batch.activate'))->toBeTrue();
    });

    it('ships the abilities the navigation needs, and draws controls only for what is held', function () {
        $manager = testPlatformStaff(PlatformRole::KycManager);
        $admin = testPlatformStaff(PlatformRole::Admin);

        $managerProps = $this->actingAs($manager, 'web')->get(route('admin.kyc.batch-builder.index'))->viewData('page')['props'];

        foreach (['view', 'create', 'activate', 'assign', 'review', 'view_sensitive_data'] as $ability) {
            expect($managerProps['permissions']['kyc_batch.'.$ability])->toBeTrue("kyc_batch.{$ability}");
        }

        expect($managerProps['can'])->toBe(['create' => true, 'activate' => true]);

        $adminProps = $this->actingAs($admin, 'web')->get(route('admin.kyc.batch-builder.index'))->viewData('page')['props'];

        expect($adminProps['permissions']['kyc_batch.view'])->toBeTrue()
            ->and($adminProps['permissions']['kyc_batch.create'])->toBeFalse()
            ->and($adminProps['permissions']['kyc_batch.activate'])->toBeFalse()
            ->and($adminProps['can'])->toBe(['create' => false, 'activate' => false]);
    });
});

describe('typed values and files', function () {
    beforeEach(function () {
        $this->batch = kycSecBatch($this->admin);
        [$this->round, $this->applicant] = kycSecSubmittedPartner($this->batch);
        $this->tin = ['requirement' => kycSecKey($this->batch, 'TIN'), 'slot' => 'tin_number'];
    });

    it('never puts a typed value, a path or a checksum in the review payload', function () {
        $this->actingAs($this->admin, 'web')->get(route('admin.kyc.show', $this->round))
            ->assertInertia(function (Assert $page) {
                $json = json_encode($page->toArray()['props']['batch']);

                expect($json)->not->toContain(KYC_SEC_TIN)
                    ->and($json)->not->toContain('checksum')
                    ->and($json)->not->toContain('"path"')
                    ->and($json)->not->toContain('"disk"')
                    ->and($json)->toContain('9012');
            });
    });

    it('reveals a value in full only to someone holding the sensitive permission, and records that it was read', function () {
        $viewer = kycSecStaff(kycSecPermission(PermissionModule::Kyc, PermissionAction::View));

        $this->actingAs($viewer, 'web')->getJson(route('admin.kyc.evidence-value', [$this->round, ...$this->tin]))->assertForbidden();

        $viewer->givePermissionTo(kycSecPermission(PermissionModule::KycBatch, PermissionAction::ViewSensitiveData));

        $response = $this->actingAs($viewer, 'web')->getJson(route('admin.kyc.evidence-value', [$this->round, ...$this->tin]));

        $response->assertOk()->assertJson(['value' => KYC_SEC_TIN]);
        expect($response->headers->get('Cache-Control'))->toContain('no-store');

        $entry = DB::table('audit_logs')->where('action', 'kyc.value_viewed')->sole();

        expect($entry->after)->toContain('tin_number')->and($entry->after)->not->toContain(KYC_SEC_TIN);
    });

    it('reveals only a typed value the batch asks for, never a file slot', function () {
        $this->actingAs($this->admin, 'web')
            ->getJson(route('admin.kyc.evidence-value', [$this->round, 'requirement' => kycSecKey($this->batch, 'Passport'), 'slot' => 'photo_page']))
            ->assertNotFound();
    });

    it('keeps the evidence route open to the owner and to those who may open documents, and to nobody else', function () {
        $document = KycDocument::query()->latest('id')->firstOrFail();
        $stranger = testBusinessAccount(AccountStatus::KycPending)->owner;
        $plainStaff = kycSecStaff(kycSecPermission(PermissionModule::Kyc, PermissionAction::View));
        $opener = kycSecStaff(kycSecPermission(PermissionModule::Kyc, PermissionAction::ViewKycDocuments));

        $this->actingAs($this->applicant)->get(route('kyc.documents.show', $document))->assertOk();
        $this->actingAs($opener, 'web')->get(route('kyc.documents.show', $document))->assertOk();
        $this->actingAs($stranger)->get(route('kyc.documents.show', $document))->assertForbidden();
        $this->actingAs($plainStaff, 'web')->get(route('kyc.documents.show', $document))->assertForbidden();
        auth()->logout();
        $this->get(route('kyc.documents.show', $document))->assertRedirect(route('login'));
    });

    it('refuses a file of the wrong kind or the wrong size for its slot', function () {
        $applicant = testBusinessAccount(AccountStatus::KycPending)->owner;
        $this->actingAs($applicant)->get(route('kyc.create'));
        $nid = kycSecKey($this->batch, 'National ID');

        $this->actingAs($applicant)->post(route('kyc.evidence.file'), ['requirement' => $nid, 'slot' => 'front_side', 'file' => UploadedFile::fake()->create('scan.pdf', 50, 'application/pdf')])
            ->assertSessionHasErrors('file');
        $this->actingAs($applicant)->post(route('kyc.evidence.file'), ['requirement' => $nid, 'slot' => 'front_side', 'file' => UploadedFile::fake()->create('shell.php', 10, 'image/jpeg')])
            ->assertSessionHasErrors('file');
        $this->actingAs($applicant)->post(route('kyc.evidence.file'), ['requirement' => $nid, 'slot' => 'front_side', 'file' => kycSecImage('big.jpg', 3000)])
            ->assertSessionHasErrors('file');

        expect(KycDocument::query()->where('kyc_submission_id', KycSubmission::query()->where('business_account_id', $applicant->accountMembership()->value('business_account_id'))->value('id'))->count())->toBe(0);
    });

    it('does not let accepted evidence be replaced, file or value', function () {
        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.evidence-review', $this->round), ['verdicts' => kycSecVerdicts($this->batch)]);
        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.decide', $this->round), ['outcome' => 'approve']);

        // The round is closed, so nothing can be changed at all.
        $this->actingAs($this->applicant)->post(route('kyc.evidence.file'), ['requirement' => kycSecKey($this->batch, 'Passport'), 'slot' => 'photo_page', 'file' => kycSecImage('new.jpg')])
            ->assertSessionHasErrors('file');
        $this->actingAs($this->applicant)->post(route('kyc.evidence.value'), [...$this->tin, 'value' => '999'])
            ->assertSessionHasErrors('value');

        expect(KycDocument::query()->whereNull('superseded_at')->count())->toBe(1);
    });

    it('keeps one round\'s verdicts off another round', function () {
        [$other] = kycSecSubmittedPartner($this->batch);

        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.evidence-review', $this->round), ['verdicts' => kycSecVerdicts($this->batch)])->assertSessionHasNoErrors();

        $this->actingAs($this->admin, 'web')->get(route('admin.kyc.show', $other))
            ->assertInertia(fn (Assert $page) => $page->where('batch.groups.0.options.1.slots.0.current.verdict', null));

        expect(KycEvidenceReview::query()->where('kyc_submission_id', $other->id)->count())->toBe(0);
    });

    it('writes nothing a person gave into the audit log', function () {
        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.evidence-review', $this->round), [
            'verdicts' => [[...kycSecVerdicts($this->batch)[0], 'status' => 'rejected', 'note' => 'Private instruction text.']],
        ]);
        $this->actingAs($this->admin, 'web')->getJson(route('admin.kyc.evidence-value', [$this->round, ...$this->tin]));

        $everything = DB::table('audit_logs')->get()->map(fn ($row) => json_encode($row))->implode("\n");

        expect($everything)->not->toContain(KYC_SEC_TIN)
            ->and($everything)->not->toContain('passport-secret-name')
            ->and($everything)->not->toContain('Private instruction text');
    });
});

describe('guard isolation', function () {
    it('keeps a Supplier out of staff screens and staff out of Supplier screens', function () {
        $batch = kycSecBatch($this->admin);
        $supplier = Supplier::factory()->kycPending()->create();
        $round = KycSubmission::create(['business_account_id' => testBusinessAccount(AccountStatus::KycPending)->id, 'status' => KycStatus::Submitted, 'round' => 1]);

        // A Supplier alone.
        Auth::guard('web')->logout();
        $this->actingAs($supplier, 'supplier');
        kycSecFresh();
        $this->get(route('admin.kyc.batch-builder.index'))->assertRedirect();
        kycSecFresh();
        $this->post(route('admin.kyc.evidence-review', $round), ['verdicts' => []])->assertRedirect();

        // A staff member alone.
        Auth::guard('supplier')->logout();
        $this->actingAs($this->admin, 'web');
        kycSecFresh();
        $this->get(route('supplier.kyc.create'))->assertRedirect();
        kycSecFresh();
        $this->post(route('supplier.kyc.evidence.file'), ['requirement' => 'x', 'slot' => 'y'])->assertRedirect();

        expect($batch->refresh()->exists)->toBeTrue()->and(SupplierKycDocument::query()->count())->toBe(0);
    });

    it('describes only the person a route is for when a staff and a Supplier session both exist', function () {
        kycSecBatch($this->admin, 'supplier');
        $supplier = Supplier::factory()->kycPending()->create();

        // Both sessions in one browser, each on its own guard, as a real visit has them.
        $this->actingAs($supplier, 'supplier');
        $this->actingAs($this->admin, 'web');

        kycSecFresh();
        $this->get(route('admin.kyc.batch-builder.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.public_id', $this->admin->public_id)
                // The Supplier is not described on a staff page.
                ->where('supplierAccount', null));

        kycSecFresh();
        $this->get(route('supplier.kyc.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // The staff member's profile is not on a Supplier page.
                ->where('auth.user', null)
                ->where('supplierAccount.reference', $supplier->reference)
                ->where('unreadNotificationCount', 0));

        // And back again: the order of visits changes nothing.
        kycSecFresh();
        $this->get(route('admin.kyc.batch-builder.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.user.public_id', $this->admin->public_id)->where('supplierAccount', null));
    });
});

describe('the live selfie', function () {
    beforeEach(function () {
        $this->batch = kycSecBatch($this->admin, 'account', 'required');
        $this->applicant = testBusinessAccount(AccountStatus::KycPending)->owner;
        $this->actingAs($this->applicant)->get(route('kyc.create'));
        $this->passport = kycSecKey($this->batch, 'Passport');
        $this->tin = kycSecKey($this->batch, 'TIN');
    });

    it('cannot be sent off without it, however complete the rest is', function () {
        $this->actingAs($this->applicant)->post(route('kyc.evidence.file'), ['requirement' => $this->passport, 'slot' => 'photo_page', 'file' => kycSecImage()]);
        $this->actingAs($this->applicant)->post(route('kyc.evidence.value'), ['requirement' => $this->tin, 'slot' => 'tin_number', 'value' => KYC_SEC_TIN]);

        $this->actingAs($this->applicant)->post(route('kyc.submit'), ['confirm' => 1])->assertSessionHasErrors('submission');

        expect(KycSubmission::query()->sole()->status)->toBe(KycStatus::Draft);
    });

    it('is the camera and only the camera unless the batch allows a picked photo', function () {
        $this->actingAs($this->applicant)->post(route('kyc.evidence.file'), ['requirement' => '__selfie', 'slot' => 'capture', 'file' => kycSecImage('upload.jpg')])
            ->assertSessionHasErrors('file');

        $this->actingAs($this->applicant)->post(route('kyc.evidence.file'), ['requirement' => '__selfie', 'slot' => 'capture', 'file' => kycSecImage('live.jpg'), 'captured_live' => 1])
            ->assertSessionHasNoErrors();

        expect(KycDocument::query()->sole()->captured_live)->toBeTrue();
    });

    it('allows a picked photo only when the batch says the camera may be unavailable', function () {
        $batch = kycSecBatch($this->admin, 'account', 'required', selfieUpload: true);
        $applicant = testBusinessAccount(AccountStatus::KycPending)->owner;
        $this->actingAs($applicant)->get(route('kyc.create'));

        // The default batch is the newer one with the fallback.
        $this->actingAs($applicant)->post(route('kyc.evidence.file'), ['requirement' => '__selfie', 'slot' => 'capture', 'file' => kycSecImage('upload.jpg')])
            ->assertSessionHasNoErrors();

        $this->actingAs($applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page->where('batch.selfie.allows_upload', true));

        expect($batch->selfie_allows_upload)->toBeTrue();
    });

    it('can be retaken until the application is sent, replacing the earlier frame', function () {
        $post = fn (string $name) => $this->actingAs($this->applicant)->post(route('kyc.evidence.file'), [
            'requirement' => '__selfie', 'slot' => 'capture', 'file' => kycSecImage($name), 'captured_live' => 1,
            'capture_meta' => ['width' => 640, 'height' => 480, 'facing' => 'user'],
        ]);

        $post('first.jpg')->assertSessionHasNoErrors();
        $firstPath = KycDocument::query()->sole()->path;

        $post('second.jpg')->assertSessionHasNoErrors();

        $document = KycDocument::query()->sole();

        expect($document->original_name)->toBe('second.jpg')
            ->and(Storage::disk('kyc')->exists($firstPath))->toBeFalse();
    });

    it('is stored privately and encrypted, with nothing but the frame size and camera kept about it', function () {
        $this->actingAs($this->applicant)->post(route('kyc.evidence.file'), [
            'requirement' => '__selfie', 'slot' => 'capture', 'file' => kycSecImage('live.jpg'), 'captured_live' => 1,
            'capture_meta' => ['width' => 640, 'height' => 480, 'facing' => 'user', 'face_vector' => '0.1', 'gps' => '23,90'],
        ]);

        $document = KycDocument::query()->sole();
        $stored = Storage::disk('kyc')->get($document->path);

        expect($document->is_encrypted)->toBeTrue()
            // Not the image's own bytes: encrypted at rest.
            ->and(str_starts_with($stored, "\xFF\xD8"))->toBeFalse()
            ->and($document->capture_meta)->toEqualCanonicalizing(['width' => 640, 'height' => 480, 'facing' => 'user'])
            // No address of it is ever given out.
            ->and(array_keys($document->toArray()))->not->toContain('path', 'disk', 'checksum');
    });

    it('cannot be retaken once a reviewer has accepted it', function () {
        $this->actingAs($this->applicant)->post(route('kyc.evidence.file'), ['requirement' => $this->passport, 'slot' => 'photo_page', 'file' => kycSecImage()]);
        $this->actingAs($this->applicant)->post(route('kyc.evidence.value'), ['requirement' => $this->tin, 'slot' => 'tin_number', 'value' => KYC_SEC_TIN]);
        $this->actingAs($this->applicant)->post(route('kyc.evidence.file'), ['requirement' => '__selfie', 'slot' => 'capture', 'file' => kycSecImage('live.jpg'), 'captured_live' => 1]);
        $this->actingAs($this->applicant)->post(route('kyc.submit'), ['confirm' => 1])->assertSessionHasNoErrors();

        $round = KycSubmission::query()->sole();
        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.evidence-review', $round), ['verdicts' => kycSecVerdicts($this->batch, selfie: true)]);

        // Sent back for the passport only: the accepted selfie stays locked.
        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.evidence-review', $round), [
            'verdicts' => [[...kycSecVerdicts($this->batch)[0], 'status' => 'rejected', 'note' => 'Blurred.']],
        ]);
        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.decide', $round), ['outcome' => 'resubmit'])->assertSessionHasNoErrors();

        $this->actingAs($this->applicant)->get(route('kyc.create'));
        $this->actingAs($this->applicant)->post(route('kyc.evidence.file'), ['requirement' => '__selfie', 'slot' => 'capture', 'file' => kycSecImage('again.jpg'), 'captured_live' => 1])
            ->assertSessionHasErrors('file');
    });
});

describe('notifications', function () {
    it('tells staff who review KYC when a Partner or a Supplier submits, naming nothing that was submitted', function () {
        $kycManager = testPlatformStaff(PlatformRole::KycManager);
        $supplierManager = testPlatformStaff(PlatformRole::SupplierManager);

        $partnerBatch = kycSecBatch($this->admin, 'account');
        kycSecSubmittedPartner($partnerBatch);

        Notification::assertSentTo($kycManager, WorkItemSubmitted::class, function (WorkItemSubmitted $notification) use ($kycManager) {
            $payload = $notification->toArray($kycManager);

            return $notification->via($kycManager) === ['database']
                && array_keys($payload) === ['event', 'reference']
                && ! str_contains(json_encode($payload), KYC_SEC_TIN);
        });
        Notification::assertNotSentTo($supplierManager, WorkItemSubmitted::class);

        kycSecBatch($this->admin, 'supplier');
        $supplier = supplierTestSignIn(Supplier::factory()->kycPending()->create());
        $this->get(route('supplier.kyc.create'));
        $this->post(route('supplier.kyc.evidence.file'), ['requirement' => kycSecKey(KycBatch::query()->where('audience', 'supplier')->firstOrFail(), 'Passport'), 'slot' => 'photo_page', 'file' => kycSecImage()]);
        $this->post(route('supplier.kyc.evidence.value'), ['requirement' => kycSecKey(KycBatch::query()->where('audience', 'supplier')->firstOrFail(), 'TIN'), 'slot' => 'tin_number', 'value' => KYC_SEC_TIN]);
        $this->post(route('supplier.kyc.submit'), ['confirm' => 1])->assertSessionHasNoErrors();

        Notification::assertSentTo($supplierManager, WorkItemSubmitted::class);
    });

    it('tells a Partner the outcome without a value, a file, a reviewer or an internal note', function () {
        $batch = kycSecBatch($this->admin);

        // Approved.
        [$round, $applicant] = kycSecSubmittedPartner($batch);
        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.evidence-review', $round), ['verdicts' => kycSecVerdicts($batch)]);
        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.decide', $round), ['outcome' => 'approve', 'internal_note' => 'Internal remark.']);

        Notification::assertSentTo($applicant, KycApproved::class, fn ($n) => $n->toArray($applicant) === ['event' => 'account.kyc_approved']);

        // Sent back.
        [$second, $secondApplicant] = kycSecSubmittedPartner($batch);
        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.evidence-review', $second), ['verdicts' => [[...kycSecVerdicts($batch)[0], 'status' => 'rejected', 'note' => 'The photo is blurred.']]]);
        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.decide', $second), ['outcome' => 'resubmit', 'internal_note' => 'Internal remark.']);

        Notification::assertSentTo($secondApplicant, KycResubmissionRequested::class, function ($n) use ($secondApplicant) {
            $json = json_encode($n->toArray($secondApplicant));

            return str_contains($json, 'The photo is blurred.')
                && ! str_contains($json, KYC_SEC_TIN)
                && ! str_contains($json, 'passport-secret-name')
                && ! str_contains($json, 'Internal remark.')
                && ! str_contains($json, $this->admin->name);
        });

        // Declined.
        [$third, $thirdApplicant] = kycSecSubmittedPartner($batch);
        $this->actingAs($this->admin, 'web')->post(route('admin.kyc.decide', $third), ['outcome' => 'reject', 'reason' => 'Internal reason text.', 'feedback' => 'We could not verify this.', 'internal_note' => 'Internal remark.']);

        Notification::assertSentTo($thirdApplicant, KycRejected::class, function ($n) use ($thirdApplicant) {
            $json = json_encode($n->toArray($thirdApplicant));

            return str_contains($json, 'We could not verify this.')
                && ! str_contains($json, 'Internal reason text.')
                && ! str_contains($json, 'Internal remark.');
        });
    });

    it('tells a Supplier the outcome without a value, a file, a reviewer or an internal reason', function () {
        $batch = kycSecBatch($this->admin, 'supplier');
        $supplier = supplierTestSignIn(Supplier::factory()->kycPending()->create());

        $this->get(route('supplier.kyc.create'));
        $this->post(route('supplier.kyc.evidence.file'), ['requirement' => kycSecKey($batch, 'Passport'), 'slot' => 'photo_page', 'file' => kycSecImage('passport-secret-name.jpg')]);
        $this->post(route('supplier.kyc.evidence.value'), ['requirement' => kycSecKey($batch, 'TIN'), 'slot' => 'tin_number', 'value' => KYC_SEC_TIN]);
        $this->post(route('supplier.kyc.submit'), ['confirm' => 1])->assertSessionHasNoErrors();

        // Sent back for the passport.
        $this->actingAs($this->admin, 'web')->post(route('admin.suppliers.kyc.evidence-review', $supplier), ['verdicts' => [
            [...kycSecVerdicts($batch)[0], 'status' => 'rejected', 'note' => 'Page cut off.'],
            kycSecVerdicts($batch)[1],
        ]]);
        $this->actingAs($this->admin, 'web')->post(route('admin.suppliers.kyc.correction.store', $supplier), ['feedback' => '', 'internal_reason' => 'Internal reason text.']);

        Notification::assertSentTo($supplier, SupplierKycCorrectionRequested::class, function ($n) use ($supplier) {
            $json = json_encode([$n->note, $n->toArray($supplier)]);

            return str_contains($json, 'Page cut off.')
                && ! str_contains($json, KYC_SEC_TIN)
                && ! str_contains($json, 'passport-secret-name')
                && ! str_contains($json, 'Internal reason text.');
        });

        // Replaced, resubmitted, approved.
        $this->post(route('supplier.kyc.evidence.file'), ['requirement' => kycSecKey($batch, 'Passport'), 'slot' => 'photo_page', 'file' => kycSecImage('new.jpg')]);
        $this->post(route('supplier.kyc.submit'), ['confirm' => 1])->assertSessionHasNoErrors();
        $this->actingAs($this->admin, 'web')->post(route('admin.suppliers.kyc.evidence-review', $supplier), ['verdicts' => kycSecVerdicts($batch)])->assertSessionHasNoErrors();
        $this->actingAs($this->admin, 'web')->post(route('admin.suppliers.approval.store', $supplier), ['note' => 'Welcome.'])->assertSessionHasNoErrors();

        Notification::assertSentTo($supplier, SupplierApproved::class);
    });

    it('tells a Supplier when they were not approved, with the note meant for them only', function () {
        $batch = kycSecBatch($this->admin, 'supplier');
        $supplier = supplierTestSignIn(Supplier::factory()->kycPending()->create());

        $this->get(route('supplier.kyc.create'));
        $this->post(route('supplier.kyc.evidence.file'), ['requirement' => kycSecKey($batch, 'Passport'), 'slot' => 'photo_page', 'file' => kycSecImage()]);
        $this->post(route('supplier.kyc.evidence.value'), ['requirement' => kycSecKey($batch, 'TIN'), 'slot' => 'tin_number', 'value' => KYC_SEC_TIN]);
        $this->post(route('supplier.kyc.submit'), ['confirm' => 1]);

        $this->actingAs($this->admin, 'web')->post(route('admin.suppliers.rejection.store', $supplier), ['reason' => 'Internal reason text.', 'note' => 'We could not verify you.'])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($supplier, SupplierRejected::class, function ($n) use ($supplier) {
            $json = json_encode([$n->note, $n->toArray($supplier)]);

            return str_contains($json, 'We could not verify you.') && ! str_contains($json, 'Internal reason text.') && ! str_contains($json, KYC_SEC_TIN);
        });
    });
});
