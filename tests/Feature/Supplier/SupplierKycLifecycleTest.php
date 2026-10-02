<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Supplier\Enums\SupplierKycStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierKycDocument;
use App\Notifications\Supplier\SupplierApproved;
use App\Notifications\Supplier\SupplierKycCorrectionRequested;
use App\Notifications\Supplier\SupplierKycSubmitted;
use App\Notifications\Supplier\SupplierReactivated;
use App\Notifications\Supplier\SupplierRejected;
use App\Notifications\Supplier\SupplierSuspended;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('kyc');
    Notification::fake();
});

/** A Supplier waiting on the KYC step, signed in. */
function supplierKycTestApplicant(): Supplier
{
    return supplierTestSignIn(Supplier::factory()->kycPending()->create());
}

function supplierKycTestUpload(string $type = 'trade_licence', ?UploadedFile $file = null)
{
    return test()->post(route('supplier.kyc.documents.store'), [
        'document_type' => $type,
        'file' => $file ?? UploadedFile::fake()->create('my-private-name.pdf', 120, 'application/pdf'),
    ]);
}

/** A submitted application, ready for a reviewer. */
function supplierKycTestSubmitted(): Supplier
{
    $supplier = supplierKycTestApplicant();
    supplierKycTestUpload();
    test()->post(route('supplier.kyc.submit'))->assertSessionHasNoErrors();

    return $supplier->refresh();
}

test('a supplier uploads KYC documents to private, encrypted storage under a random name', function () {
    $supplier = supplierKycTestApplicant();

    $file = UploadedFile::fake()->create('my-private-name.pdf', 120, 'application/pdf');
    supplierKycTestUpload('trade_licence', $file)->assertSessionHasNoErrors();

    $document = SupplierKycDocument::query()->firstOrFail();

    expect($document->path)->toStartWith('supplier-kyc/'.$supplier->id.'/')
        ->and($document->path)->not->toContain('my-private-name')
        ->and($document->disk)->toBe('kyc')
        ->and($document->is_encrypted)->toBeTrue();

    Storage::disk('kyc')->assertExists($document->path);
    expect(Storage::disk('kyc')->get($document->path))->not->toBe(file_get_contents($file->getRealPath()));

    // The storage path never reaches a serialised model or a page prop.
    expect($document->toArray())->not->toHaveKeys(['path', 'disk', 'checksum']);
    $this->get(route('supplier.kyc.create'))
        ->assertInertia(fn (Assert $page) => $page->component('supplier/kyc/index')
            ->where('round.documents.0.original_name', 'my-private-name.pdf')
            ->missing('round.documents.0.path'));
});

test('KYC uploads validate type, extension, size and document type', function (string $name, string $mime, int $kilobytes, string $type) {
    supplierKycTestApplicant();

    supplierKycTestUpload($type, UploadedFile::fake()->create($name, $kilobytes, $mime))->assertSessionHasErrors();

    expect(SupplierKycDocument::query()->count())->toBe(0);
})->with([
    'an executable' => ['run.exe', 'application/x-msdownload', 10, 'trade_licence'],
    'a script with a pdf mime type' => ['evil.php', 'application/pdf', 10, 'trade_licence'],
    'over five megabytes' => ['big.pdf', 'application/pdf', 6000, 'trade_licence'],
    'an unknown document type' => ['ok.pdf', 'application/pdf', 10, 'passport_of_my_cat'],
]);

test('submitting KYC moves the round and the supplier under review, records history and audit, and locks the round', function () {
    $supplier = supplierKycTestSubmitted();

    $round = $supplier->kycSubmissions()->firstOrFail();

    expect($supplier->status)->toBe(SupplierStatus::UnderReview)
        ->and($round->status)->toBe(SupplierKycStatus::UnderReview)
        ->and($round->submitted_at)->not->toBeNull()
        ->and($supplier->statusHistory()->pluck('new_status')->map->value->all())->toContain('under_review');

    Notification::assertSentTo($supplier, SupplierKycSubmitted::class);
    expect(AuditLog::query()->where('action', 'supplier_kyc.submitted')->count())->toBe(1);

    // Read-only from here: no new document, and the page says so.
    supplierKycTestUpload('nid_front')->assertSessionHasErrors('file');
    expect($round->documents()->count())->toBe(1);

    $this->get(route('supplier.kyc.create'))
        ->assertInertia(fn (Assert $page) => $page->where('round.is_editable', false));
});

test('a submission without a document is refused', function () {
    supplierKycTestApplicant();

    $this->post(route('supplier.kyc.submit'))->assertSessionHasErrors('submission');
});

test('a supplier still awaiting email and mobile verification cannot submit KYC', function () {
    $supplier = supplierTestSignIn(Supplier::factory()->create(['status' => SupplierStatus::VerificationPending]));
    supplierKycTestUpload()->assertSessionHasNoErrors();

    $this->post(route('supplier.kyc.submit'))
        ->assertSessionHasErrors(['submission' => 'Verify your email and mobile number before submitting KYC.']);

    expect($supplier->refresh()->status)->toBe(SupplierStatus::VerificationPending);
});

test('a supplier only ever sees its own KYC round and documents', function () {
    $other = Supplier::factory()->kycPending()->create();
    $otherRound = $other->kycSubmissions()->create(['round' => 1, 'status' => SupplierKycStatus::Draft]);
    $otherRound->documents()->create([
        'document_type' => 'trade_licence', 'disk' => 'kyc', 'path' => 'supplier-kyc/x/y/z',
        'original_name' => 'theirs.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'checksum' => str_repeat('a', 64),
    ]);

    supplierKycTestApplicant();

    $this->get(route('supplier.kyc.create'))
        ->assertInertia(fn (Assert $page) => $page->where('round.documents', []));
});

test('staff can request a correction, the supplier resubmits, and staff approves', function () {
    $supplier = supplierKycTestSubmitted();
    $staff = testPlatformStaff(PlatformRole::SupplierManager);

    $this->actingAs($staff)
        ->post(route('admin.suppliers.kyc.correction.store', $supplier), ['feedback' => 'The licence scan is blurry.'])
        ->assertSessionHasNoErrors();

    $supplier->refresh();
    expect($supplier->status)->toBe(SupplierStatus::CorrectionRequired);
    Notification::assertSentTo($supplier, SupplierKycCorrectionRequested::class);

    // Resubmission: same round, one more document.
    supplierTestSignIn($supplier);
    supplierKycTestUpload('tin_certificate')->assertSessionHasNoErrors();
    $this->post(route('supplier.kyc.submit'))->assertSessionHasNoErrors();
    expect($supplier->refresh()->status)->toBe(SupplierStatus::UnderReview)
        ->and(AuditLog::query()->where('action', 'supplier_kyc.resubmitted')->count())->toBe(1)
        ->and($supplier->kycSubmissions()->count())->toBe(1)
        ->and($supplier->kycSubmissions()->first()->documents()->count())->toBe(2);

    $this->actingAs($staff)->post(route('admin.suppliers.approval.store', $supplier))->assertSessionHasNoErrors();

    expect($supplier->refresh()->status)->toBe(SupplierStatus::Approved)
        ->and($supplier->isOperational())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'supplier.approved')->count())->toBe(1);
    Notification::assertSentTo($supplier, SupplierApproved::class);
});

test('approval activates operational access and nothing before it does', function () {
    $supplier = supplierKycTestSubmitted();

    $this->get(route('supplier.listings.index'))->assertForbidden();

    $staff = testPlatformStaff(PlatformRole::SupplierManager);
    $this->actingAs($staff)->post(route('admin.suppliers.approval.store', $supplier))->assertSessionHasNoErrors();

    // The guard caches the model it logged in; a real request reloads it.
    supplierTestSignIn($supplier->refresh());

    $this->get(route('supplier.listings.index'))->assertOk();
});

test('an authenticated supplier page renders with the supplier identity shared and no client account', function () {
    $supplier = supplierTestSignIn(Supplier::factory()->create());

    $this->get(route('supplier.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('supplier/dashboard')
            ->where('supplierAccount.reference', $supplier->reference)
            ->where('supplierAccount.operational', true)
            ->where('auth.user', null)
            ->where('account', null));
});

test('rejection, suspension and reactivation each require a reason', function () {
    $staff = testPlatformStaff(PlatformRole::SupplierManager);

    $applicant = supplierKycTestSubmitted();
    $this->actingAs($staff)->post(route('admin.suppliers.rejection.store', $applicant), ['reason' => ''])->assertSessionHasErrors('reason');
    expect($applicant->refresh()->status)->toBe(SupplierStatus::UnderReview);

    $this->post(route('admin.suppliers.rejection.store', $applicant), ['reason' => 'Licence is expired.', 'note' => 'Please reapply.']);
    expect($applicant->refresh()->status)->toBe(SupplierStatus::Rejected);
    Notification::assertSentTo($applicant, SupplierRejected::class);

    $approved = Supplier::factory()->create();
    $this->post(route('admin.suppliers.suspension.store', $approved), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post(route('admin.suppliers.suspension.store', $approved), ['reason' => 'Counterfeit complaint.']);
    expect($approved->refresh()->status)->toBe(SupplierStatus::Suspended);
    Notification::assertSentTo($approved, SupplierSuspended::class);

    // A suspended Supplier keeps its login but loses every operational route.
    supplierTestSignIn($approved);
    $this->get(route('supplier.dashboard'))->assertOk();
    $this->get(route('supplier.listings.index'))->assertForbidden();

    $this->actingAs($staff)->post(route('admin.suppliers.reactivation.store', $approved), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post(route('admin.suppliers.reactivation.store', $approved), ['reason' => 'Complaint withdrawn.']);
    expect($approved->refresh()->status)->toBe(SupplierStatus::Approved);
    Notification::assertSentTo($approved, SupplierReactivated::class);

    expect(AuditLog::query()->whereIn('action', ['supplier.rejected', 'supplier.suspended', 'supplier.reactivated'])->count())->toBe(3);
});

test('supplier decisions are refused to staff without the permission', function (PlatformRole $role, string $route, bool $allowed) {
    $supplier = supplierKycTestSubmitted();
    $staff = testPlatformStaff($role);

    $response = $this->actingAs($staff)->post(route($route, $supplier), ['reason' => 'A reason.', 'note' => 'n', 'feedback' => 'f']);

    $allowed ? $response->assertSessionHasNoErrors() : $response->assertForbidden();
})->with([
    'product manager cannot approve' => [PlatformRole::ProductManager, 'admin.suppliers.approval.store', false],
    'product manager cannot reject' => [PlatformRole::ProductManager, 'admin.suppliers.rejection.store', false],
    'admin cannot approve' => [PlatformRole::Admin, 'admin.suppliers.approval.store', false],
    'supplier manager can reject' => [PlatformRole::SupplierManager, 'admin.suppliers.rejection.store', true],
]);

test('a supplier session can never reach the staff supplier screens', function () {
    $supplier = supplierKycTestApplicant();

    $this->get(route('admin.suppliers.index'))->assertRedirect(route('login'));
    $this->get(route('admin.suppliers.show', $supplier))->assertRedirect(route('login'));
});

test('staff cannot open a document through another supplier', function () {
    $supplier = supplierKycTestSubmitted();
    $document = $supplier->kycSubmissions()->first()->documents()->first();
    $stranger = Supplier::factory()->create();

    $staff = testPlatformStaff(PlatformRole::SupplierManager);

    $this->actingAs($staff)
        ->get(route('admin.suppliers.kyc.documents.show', [$stranger, $document]))
        ->assertNotFound();
});

test('viewing a document is audited without ever writing its storage path', function () {
    $supplier = supplierKycTestSubmitted();
    $document = $supplier->kycSubmissions()->first()->documents()->first();
    $staff = testPlatformStaff(PlatformRole::SupplierManager);

    $this->actingAs($staff)
        ->get(route('admin.suppliers.kyc.documents.show', [$supplier, $document]))
        ->assertOk();

    $viewed = AuditLog::query()->where('action', 'supplier_kyc.document_viewed')->firstOrFail();

    expect(json_encode($viewed->getAttributes()))->not->toContain('supplier-kyc/')
        ->and($viewed->actor_id)->toBe($staff->id);
});

test('no audit row written by the KYC lifecycle contains a storage path or a secret', function () {
    $supplier = supplierKycTestSubmitted();
    $staff = testPlatformStaff(PlatformRole::SupplierManager);
    $this->actingAs($staff)->post(route('admin.suppliers.approval.store', $supplier));

    foreach (AuditLog::query()->get() as $row) {
        $serialised = json_encode($row->getAttributes());

        expect($serialised)->not->toContain('supplier-kyc/')
            ->not->toContain('payout')
            ->not->toContain($supplier->password);
    }
});

test('the staff supplier queue filters by status and requires supplier.view', function () {
    Supplier::factory()->count(2)->create();
    Supplier::factory()->suspended()->create();

    $staff = testPlatformStaff(PlatformRole::SupplierManager);

    $this->actingAs($staff)->get(route('admin.suppliers.index', ['status' => 'suspended']))
        ->assertInertia(fn (Assert $page) => $page->component('admin/suppliers/index')->has('suppliers.data', 1));

    $this->actingAs(testPlatformStaff(PlatformRole::InventoryManager))
        ->get(route('admin.suppliers.index'))->assertForbidden();
});
