<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Kyc\Enums\KycAudience;
use App\Domain\Kyc\Enums\KycCompletionRule;
use App\Domain\Kyc\Models\KycBatch;
use App\Domain\Kyc\Models\KycBatchGroup;
use App\Domain\Kyc\Models\KycDocument;
use App\Domain\Kyc\Models\KycDocumentType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
 * The app names a KYC requirement by `document_type`, as it does on the flat
 * list's upload. The batch endpoints take that name as well as `requirement`.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('kyc');
    Notification::fake();

    $batch = KycBatch::create([
        'key' => 'mobile_type_batch',
        'name' => 'Mobile type',
        'audience' => KycAudience::Account,
        'is_default' => true,
        'selfie_mode' => 'off',
    ]);

    $type = KycDocumentType::factory()->key('mobile_type_passport')->create(['name' => 'Passport', 'audience' => KycAudience::Both]);
    $type->slots()->create([
        'key' => 'bio_page', 'label' => 'Bio page', 'is_required' => true, 'evidence_type' => 'image',
        'accepted_mime_types' => ['image/jpeg', 'image/png'], 'max_size_kb' => 2048, 'sort_order' => 0,
    ]);

    $group = KycBatchGroup::create(['kyc_batch_id' => $batch->id, 'key' => 'identity', 'name' => 'Identity', 'completion_rule' => KycCompletionRule::Any, 'sort_order' => 0]);
    $group->items()->create(['kyc_document_type_id' => $type->id, 'sort_order' => 0]);

    $this->applicant = testBusinessAccount(AccountStatus::KycPending)->owner;
    Sanctum::actingAs($this->applicant);
});

it('attaches a file named by document_type, as the app sends it', function () {
    $this->post(route('mobile.v1.kyc.evidence.file'), [
        'document_type' => 'mobile_type_passport',
        'slot' => 'bio_page',
        'file' => UploadedFile::fake()->image('passport.jpg')->size(100),
    ], ['Accept' => 'application/json'])->assertOk();

    expect(KycDocument::query()->count())->toBe(1);
});

it('still attaches a file named by requirement', function () {
    $this->post(route('mobile.v1.kyc.evidence.file'), [
        'requirement' => 'mobile_type_passport',
        'slot' => 'bio_page',
        'file' => UploadedFile::fake()->image('passport.jpg')->size(100),
    ], ['Accept' => 'application/json'])->assertOk();

    expect(KycDocument::query()->count())->toBe(1);
});

it('refuses a file that names neither document_type nor requirement', function () {
    $this->post(route('mobile.v1.kyc.evidence.file'), [
        'slot' => 'bio_page',
        'file' => UploadedFile::fake()->image('passport.jpg')->size(100),
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('requirement');

    expect(KycDocument::query()->count())->toBe(0);
});

it('lists each option\'s document_type in the form', function () {
    $options = $this->getJson(route('mobile.v1.kyc.show'))
        ->assertOk()
        ->json('data.batch.groups.0.options');

    expect($options[0]['key'])->toBe('mobile_type_passport')
        ->and($options[0]['document_type'])->toBe('mobile_type_passport');
});
