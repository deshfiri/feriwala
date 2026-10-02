<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Kyc\Enums\KycAudience;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Queries\ApplicableRequirements;
use App\Domain\Supplier\Models\Supplier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Supplier KYC follows the administrator's catalogue: what a Supplier is asked
 * for is configured under Admin -> KYC document types, not fixed in code.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('kyc');
    Notification::fake();
});

function supplierDynamicKycApplicant(): Supplier
{
    return supplierTestSignIn(Supplier::factory()->kycPending()->create());
}

function supplierDynamicKycType(KycAudience $audience, array $state = []): KycDocumentType
{
    return KycDocumentType::factory()->create(['audience' => $audience, ...$state]);
}

it('asks a supplier only for the types marked for suppliers', function () {
    supplierDynamicKycType(KycAudience::Supplier, ['key' => 'gst_cert', 'name' => 'GST certificate']);
    supplierDynamicKycType(KycAudience::Both, ['key' => 'owner_id', 'name' => 'Owner ID']);
    supplierDynamicKycType(KycAudience::Account, ['key' => 'partner_only', 'name' => 'Partner only']);
    supplierDynamicKycType(KycAudience::Supplier, ['key' => 'paused', 'name' => 'Paused', 'is_active' => false]);

    supplierDynamicKycApplicant();

    $this->get(route('supplier.kyc.create'))
        ->assertInertia(fn (Assert $page) => $page->component('supplier/kyc/index')
            ->where('requirements.*.key', ['gst_cert', 'owner_id']));
});

it('does not ask clients or partners for a supplier-only type', function () {
    supplierDynamicKycType(KycAudience::Supplier, ['key' => 'gst_cert']);
    supplierDynamicKycType(KycAudience::Both, ['key' => 'owner_id']);

    $account = testBusinessAccount();

    $keys = app(ApplicableRequirements::class)->forUser($account)->pluck('key')->all();

    expect($keys)->toBe(['owner_id']);
});

it('refuses submission until every required item is provided, then accepts it', function () {
    supplierDynamicKycType(KycAudience::Supplier, ['key' => 'gst_cert', 'name' => 'GST certificate']);
    supplierDynamicKycType(KycAudience::Supplier, ['key' => 'optional_doc', 'name' => 'Optional', 'is_required' => false]);
    supplierDynamicKycApplicant();

    $this->get(route('supplier.kyc.create'));

    $this->post(route('supplier.kyc.submit'))
        ->assertSessionHasErrors(['submission' => 'Still required: GST certificate.']);

    $this->post(route('supplier.kyc.documents.store'), [
        'document_type' => 'gst_cert',
        'file' => UploadedFile::fake()->create('gst.pdf', 50, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $this->post(route('supplier.kyc.submit'))->assertSessionHasNoErrors();
});

it('takes a typed value for a value-only item and stores it encrypted', function () {
    supplierDynamicKycType(KycAudience::Supplier, [
        'key' => 'tin', 'name' => 'TIN', 'requires_file' => false, 'requires_value' => true, 'value_label' => 'TIN number',
    ]);
    $supplier = supplierDynamicKycApplicant();

    $this->post(route('supplier.kyc.documents.store'), ['document_type' => 'tin', 'value' => '123456789012'])
        ->assertSessionHasNoErrors();

    $round = $supplier->kycSubmissions()->firstOrFail();

    expect($round->fields()->firstOrFail()->value)->toBe('123456789012')
        ->and(DB::table('supplier_kyc_fields')->value('value'))->not->toContain('123456789012');

    $this->get(route('supplier.kyc.create'))
        ->assertInertia(fn (Assert $page) => $page->where('requirements.0.value_preview', '••••••••9012'));

    $this->post(route('supplier.kyc.submit'))->assertSessionHasNoErrors();
});

it('refuses a file for a value-only item and a type that is not in the round', function () {
    supplierDynamicKycType(KycAudience::Supplier, ['key' => 'tin', 'requires_file' => false, 'requires_value' => true]);
    supplierDynamicKycApplicant();

    $this->post(route('supplier.kyc.documents.store'), [
        'document_type' => 'tin',
        'file' => UploadedFile::fake()->create('tin.pdf', 10, 'application/pdf'),
    ])->assertSessionHasErrors('file');

    $this->post(route('supplier.kyc.documents.store'), ['document_type' => 'nid_front', 'value' => 'x'])
        ->assertSessionHasErrors('document_type');
});

it('judges an upload against the formats and size the round was opened with', function () {
    supplierDynamicKycType(KycAudience::Supplier, ['key' => 'photo', 'accepted_mime_types' => ['image/png'], 'max_size_kb' => 100]);
    supplierDynamicKycApplicant();

    $this->post(route('supplier.kyc.documents.store'), [
        'document_type' => 'photo',
        'file' => UploadedFile::fake()->create('scan.pdf', 10, 'application/pdf'),
    ])->assertSessionHasErrors('file');

    $this->post(route('supplier.kyc.documents.store'), [
        'document_type' => 'photo',
        'file' => UploadedFile::fake()->create('big.png', 500, 'image/png'),
    ])->assertSessionHasErrors('file');

    $this->post(route('supplier.kyc.documents.store'), [
        'document_type' => 'photo',
        'file' => UploadedFile::fake()->create('ok.png', 50, 'image/png'),
    ])->assertSessionHasNoErrors();
});

it('keeps a round on the requirements it opened with when the catalogue changes', function () {
    $type = supplierDynamicKycType(KycAudience::Supplier, ['key' => 'gst_cert', 'name' => 'GST certificate']);
    $supplier = supplierDynamicKycApplicant();

    $this->get(route('supplier.kyc.create'));

    $type->update(['name' => 'Renamed', 'is_required' => false]);
    supplierDynamicKycType(KycAudience::Supplier, ['key' => 'added_later']);

    $this->get(route('supplier.kyc.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirements.*.key', ['gst_cert'])
            ->where('requirements.0.name', 'GST certificate')
            ->where('requirements.0.is_required', true));

    expect($supplier->kycSubmissions()->firstOrFail()->requirements()->count())->toBe(1);
});

it('falls back to the built-in documents while no supplier requirement is configured', function () {
    supplierDynamicKycType(KycAudience::Account, ['key' => 'partner_only']);
    supplierDynamicKycApplicant();

    $this->get(route('supplier.kyc.create'))
        ->assertInertia(fn (Assert $page) => $page->has('requirements', 6)->where('requirements.0.key', 'trade_licence'));
});

it('lets an administrator choose who a requirement is asked of, and refuses anything else', function () {
    $staff = testPlatformStaff(PlatformRole::Admin);

    $payload = [
        'key' => 'supplier_doc', 'name' => 'Supplier doc', 'is_required' => 1, 'is_active' => 1,
        'requires_file' => 1, 'requires_value' => 0, 'accepted_mime_types' => ['application/pdf'], 'max_size_kb' => 1024,
    ];

    $this->actingAs($staff)
        ->post(route('admin.kyc.document-types.store'), [...$payload, 'audience' => 'supplier'])
        ->assertSessionHasNoErrors();

    expect(KycDocumentType::query()->where('key', 'supplier_doc')->value('audience'))->toBe(KycAudience::Supplier);

    $this->post(route('admin.kyc.document-types.store'), [...$payload, 'key' => 'other_doc', 'audience' => 'everyone'])
        ->assertSessionHasErrors('audience');
});
