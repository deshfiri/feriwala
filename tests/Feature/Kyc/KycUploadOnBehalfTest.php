<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Kyc\Enums\KycAudience;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycDocument;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierKycDocument;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
 * Staff putting KYC documents on a partner's or a Supplier's round.
 *
 * What matters: it lands on the same round and obeys the same limits as the
 * applicant's own upload, it is marked as staff-supplied and audited with a
 * reason, and it never reaches a round somebody is already deciding on.
 */

function staffUploadType(string $key = 'nid'): KycDocumentType
{
    return KycDocumentType::factory()->key($key)->create(['audience' => KycAudience::Both]);
}

function staffUploadFile(string $name = 'nid.pdf'): UploadedFile
{
    return UploadedFile::fake()->create($name, 100, 'application/pdf');
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    Storage::fake('kyc');

    $this->superAdmin = testPlatformStaff(PlatformRole::SuperAdmin);
});

describe('for a partner', function () {
    it('offers what the round asks for', function () {
        staffUploadType('nid');
        $account = testBusinessAccount(AccountStatus::KycPending);

        $this->actingAs($this->superAdmin)
            ->getJson(route('admin.accounts.kyc-upload.targets', $account->public_id))
            ->assertOk()
            ->assertJsonPath('targets.0.value', 'type:nid');
    });

    it('stores the file against the applicant’s round, marked as staff-supplied', function () {
        staffUploadType('nid');
        $account = testBusinessAccount(AccountStatus::KycPending);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.accounts.kyc-upload.store', $account->public_id), [
                'target' => 'type:nid',
                'file' => staffUploadFile(),
                'reason' => 'Applicant sent the scan by email.',
            ])
            ->assertSessionHasNoErrors();

        $document = KycDocument::query()->firstOrFail();

        expect($document->uploaded_by_staff_id)->toBe($this->superAdmin->id)
            ->and($document->submission->business_account_id)->toBe($account->id)
            ->and($document->submission->status)->toBe(KycStatus::Draft)
            ->and($document->is_encrypted)->toBeTrue();

        expect(DB::table('audit_logs')->where('action', 'kyc.document_uploaded_on_behalf')->where('actor_id', $this->superAdmin->id)->exists())->toBeTrue();
    });

    it('refuses a file the round does not accept', function () {
        staffUploadType('nid');
        $account = testBusinessAccount(AccountStatus::KycPending);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.accounts.kyc-upload.store', $account->public_id), [
                'target' => 'type:nid',
                'file' => UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload'),
                'reason' => 'Trying a wrong format.',
            ])
            ->assertSessionHasErrors('file');

        expect(KycDocument::query()->count())->toBe(0);
    });

    it('refuses a target the round does not offer', function () {
        staffUploadType('nid');
        $account = testBusinessAccount(AccountStatus::KycPending);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.accounts.kyc-upload.store', $account->public_id), [
                'target' => 'type:not_a_real_one',
                'file' => staffUploadFile(),
                'reason' => 'Crafted request.',
            ])
            ->assertSessionHasErrors('file');
    });

    it('requires a reason', function () {
        staffUploadType('nid');
        $account = testBusinessAccount(AccountStatus::KycPending);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.accounts.kyc-upload.store', $account->public_id), [
                'target' => 'type:nid',
                'file' => staffUploadFile(),
                'reason' => '',
            ])
            ->assertSessionHasErrors('reason');
    });

    it('does not touch a round that is already awaiting a decision', function () {
        staffUploadType('nid');
        $account = testBusinessAccount(AccountStatus::KycUnderReview);
        KycSubmission::create([
            'business_account_id' => $account->id,
            'status' => KycStatus::Submitted,
            'round' => 1,
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.accounts.kyc-upload.store', $account->public_id), [
                'target' => 'type:nid',
                'file' => staffUploadFile(),
                'reason' => 'Trying to add to a round under review.',
            ])
            ->assertSessionHasErrors('file');

        expect(KycDocument::query()->count())->toBe(0);
    });

    it('is refused to staff without the KYC edit permission', function () {
        staffUploadType('nid');
        $account = testBusinessAccount(AccountStatus::KycPending);
        $staff = testPlatformStaff(PlatformRole::ContentManager);

        $this->actingAs($staff)
            ->getJson(route('admin.accounts.kyc-upload.targets', $account->public_id))
            ->assertForbidden();

        $this->actingAs($staff)
            ->post(route('admin.accounts.kyc-upload.store', $account->public_id), [
                'target' => 'type:nid',
                'file' => staffUploadFile(),
                'reason' => 'Not allowed to do this.',
            ])
            ->assertForbidden();
    });

    it('is the evidence that "complete & activate" then approves', function () {
        staffUploadType('nid');
        $account = testBusinessAccount(AccountStatus::KycPending);
        $account->owner->forceFill(['email_verified_at' => null, 'mobile_verified_at' => null])->save();

        $this->actingAs($this->superAdmin)->post(route('admin.accounts.kyc-upload.store', $account->public_id), [
            'target' => 'type:nid',
            'file' => staffUploadFile(),
            'reason' => 'Applicant sent the scan by email.',
        ]);

        $this->actingAs($this->superAdmin)->post(route('admin.accounts.complete-manually', $account->public_id), [
            'reason' => 'Documents received and checked.',
        ])->assertSessionHasNoErrors();

        // One round, carrying the uploaded document, now approved — not an
        // empty second round beside a draft.
        expect(KycSubmission::query()->where('business_account_id', $account->id)->count())->toBe(1)
            ->and(KycSubmission::query()->first()->status)->toBe(KycStatus::Approved)
            ->and(KycSubmission::query()->first()->documents()->count())->toBe(1)
            ->and($account->refresh()->status)->toBe(AccountStatus::Active);
    });
});

describe('for a supplier', function () {
    it('stores the file against the Supplier’s round, marked as staff-supplied', function () {
        staffUploadType('trade_licence');
        $supplier = Supplier::factory()->kycPending()->create();

        $this->actingAs($this->superAdmin)
            ->getJson(route('admin.suppliers.kyc-upload.targets', $supplier->public_id))
            ->assertOk()
            ->assertJsonPath('targets.0.value', 'type:trade_licence');

        $this->actingAs($this->superAdmin)
            ->post(route('admin.suppliers.kyc-upload.store', $supplier->public_id), [
                'target' => 'type:trade_licence',
                'file' => staffUploadFile('licence.pdf'),
                'reason' => 'Supplier handed the licence over in person.',
            ])
            ->assertSessionHasNoErrors();

        $document = SupplierKycDocument::query()->firstOrFail();

        expect($document->uploaded_by_staff_id)->toBe($this->superAdmin->id);
        expect(DB::table('audit_logs')->where('action', 'supplier_kyc.document_uploaded_on_behalf')->exists())->toBeTrue();
    });

    it('is refused to staff without the Supplier KYC permission', function () {
        $supplier = Supplier::factory()->kycPending()->create();
        $staff = testPlatformStaff(PlatformRole::ContentManager);

        $this->actingAs($staff)
            ->getJson(route('admin.suppliers.kyc-upload.targets', $supplier->public_id))
            ->assertForbidden();
    });
});
