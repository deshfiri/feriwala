<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Kyc\Enums\KycAudience;
use App\Domain\Kyc\Enums\KycCompletionRule;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycBatch;
use App\Domain\Kyc\Models\KycBatchGroup;
use App\Domain\Kyc\Models\KycDocument;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierKycDocument;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The live selfie as a batch setting (Off / Optional / Required), enforced by the
 * server from the round's own version snapshot, for a Partner and a Supplier —
 * and the file kept private.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('kyc');
    Notification::fake();
});

/** A live batch with one required group: a Passport's bio page. */
function selfieRuleBatch(KycAudience $audience, string $selfie): KycBatch
{
    $batch = KycBatch::create([
        'key' => 'selfie_rule_'.$audience->value,
        'name' => 'Selfie rule',
        'audience' => $audience,
        'is_default' => true,
        'selfie_mode' => $selfie,
    ]);

    $type = KycDocumentType::query()->where('key', 'selfie_rule_passport')->first()
        ?? KycDocumentType::factory()->key('selfie_rule_passport')->create(['name' => 'Passport', 'audience' => KycAudience::Both]);

    if ($type->slots()->count() === 0) {
        $type->slots()->create([
            'key' => 'bio_page', 'label' => 'Bio page', 'is_required' => true, 'evidence_type' => 'image',
            'accepted_mime_types' => ['image/jpeg', 'image/png'], 'max_size_kb' => 2048, 'sort_order' => 0,
        ]);
    }

    $group = KycBatchGroup::create(['kyc_batch_id' => $batch->id, 'key' => 'identity', 'name' => 'Identity', 'completion_rule' => KycCompletionRule::Any, 'sort_order' => 0]);
    $group->items()->create(['kyc_document_type_id' => $type->id, 'sort_order' => 0]);

    return $batch;
}

function selfieRuleImage(string $name = 'photo.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name)->size(100);
}

/** The selfie, as the camera hands it over. */
function selfieRuleLive(): array
{
    return [
        'requirement' => '__selfie', 'slot' => 'capture', 'file' => selfieRuleImage('live.jpg'), 'captured_live' => 1,
        'capture_meta' => ['width' => 640, 'height' => 480, 'facing' => 'user'],
    ];
}

describe('a Partner', function () {
    beforeEach(function () {
        $this->applicant = testBusinessAccount(AccountStatus::KycPending)->owner;
    });

    function selfieRulePartnerPassport(mixed $test, mixed $applicant): void
    {
        $test->actingAs($applicant)->post(route('kyc.evidence.file'), [
            'requirement' => 'selfie_rule_passport', 'slot' => 'bio_page', 'file' => selfieRuleImage('passport.jpg'),
        ])->assertSessionHasNoErrors();
    }

    it('cannot send a required selfie\'s batch off without it, and can with it', function () {
        $batch = selfieRuleBatch(KycAudience::Account, 'required');
        $this->actingAs($this->applicant)->get(route('kyc.create'));
        selfieRulePartnerPassport($this, $this->applicant);

        $this->actingAs($this->applicant)->post(route('kyc.submit'), ['confirm' => 1])->assertSessionHasErrors('submission');
        expect(KycSubmission::query()->sole()->status)->toBe(KycStatus::Draft);

        $this->actingAs($this->applicant)->post(route('kyc.evidence.file'), selfieRuleLive())->assertSessionHasNoErrors();
        $this->actingAs($this->applicant)->post(route('kyc.submit'), ['confirm' => 1])->assertSessionHasNoErrors();

        expect(KycSubmission::query()->sole()->status)->toBe(KycStatus::Submitted)
            ->and($batch->selfie_mode->value)->toBe('required');
    });

    it('does not let an optional selfie block, and labels it optional', function () {
        selfieRuleBatch(KycAudience::Account, 'optional');

        $this->actingAs($this->applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('batch.selfie.required', false)
                ->where('batch.selfie.mode', 'optional'));

        selfieRulePartnerPassport($this, $this->applicant);
        $this->actingAs($this->applicant)->post(route('kyc.submit'), ['confirm' => 1])->assertSessionHasNoErrors();

        expect(KycSubmission::query()->sole()->status)->toBe(KycStatus::Submitted);
    });

    it('neither shows nor accepts a selfie when the batch has it off', function () {
        selfieRuleBatch(KycAudience::Account, 'disabled');

        $this->actingAs($this->applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page->where('batch.selfie', null));

        // A forged request for a slot that is not asked is refused, and stores nothing.
        $this->actingAs($this->applicant)->post(route('kyc.evidence.file'), selfieRuleLive())->assertSessionHasErrors('file');
        expect(KycDocument::query()->count())->toBe(0);

        selfieRulePartnerPassport($this, $this->applicant);
        $this->actingAs($this->applicant)->post(route('kyc.submit'), ['confirm' => 1])->assertSessionHasNoErrors();
    });

    it('keeps the rule of the version the round started on, whatever the batch becomes', function () {
        $batch = selfieRuleBatch(KycAudience::Account, 'required');
        $this->actingAs($this->applicant)->get(route('kyc.create'));
        selfieRulePartnerPassport($this, $this->applicant);

        // Staff switch the live batch's selfie off afterwards.
        $batch->forceFill(['selfie_mode' => 'disabled'])->save();

        $this->actingAs($this->applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page->where('batch.selfie.required', true));

        $this->actingAs($this->applicant)->post(route('kyc.submit'), ['confirm' => 1])->assertSessionHasErrors('submission');
    });

    it('keeps the selfie private: another person cannot open it, and no address is given out', function () {
        selfieRuleBatch(KycAudience::Account, 'required');
        $this->actingAs($this->applicant)->get(route('kyc.create'));
        $this->actingAs($this->applicant)->post(route('kyc.evidence.file'), selfieRuleLive())->assertSessionHasNoErrors();

        $document = KycDocument::query()->sole();
        $stranger = testBusinessAccount(AccountStatus::KycPending)->owner;

        $page = $this->actingAs($this->applicant)->get(route('kyc.create'));
        $page->assertInertia(fn (Assert $inertia) => $inertia->where('batch.selfie.slot.current.live', true));

        expect($page->getContent())->not->toContain($document->path)
            ->and($page->getContent())->not->toContain('r2.cloudflarestorage');

        $this->actingAs($stranger)->get(route('kyc.documents.show', $document))->assertForbidden();
        $this->actingAs($this->applicant)->get(route('kyc.documents.show', $document))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    });
});

describe('a Supplier', function () {
    beforeEach(function () {
        $this->supplier = supplierTestSignIn(Supplier::factory()->kycPending()->create());
    });

    it('cannot send a required selfie\'s batch off without it, and can with it', function () {
        selfieRuleBatch(KycAudience::Supplier, 'required');
        $this->get(route('supplier.kyc.create'));

        $this->post(route('supplier.kyc.evidence.file'), [
            'requirement' => 'selfie_rule_passport', 'slot' => 'bio_page', 'file' => selfieRuleImage('passport.jpg'),
        ])->assertSessionHasNoErrors();

        $this->post(route('supplier.kyc.submit'), ['confirm' => 1])->assertSessionHasErrors('submission');

        $this->post(route('supplier.kyc.evidence.file'), selfieRuleLive())->assertSessionHasNoErrors();
        $this->post(route('supplier.kyc.submit'), ['confirm' => 1])->assertSessionHasNoErrors();

        expect($this->supplier->kycSubmissions()->first()->status->value)->toBe('under_review');
    });

    it('does not let an optional selfie block, and ignores one when off', function () {
        selfieRuleBatch(KycAudience::Supplier, 'optional');
        $this->get(route('supplier.kyc.create'))->assertInertia(fn (Assert $page) => $page->where('batch.selfie.required', false));

        $this->post(route('supplier.kyc.evidence.file'), [
            'requirement' => 'selfie_rule_passport', 'slot' => 'bio_page', 'file' => selfieRuleImage('passport.jpg'),
        ]);
        $this->post(route('supplier.kyc.submit'), ['confirm' => 1])->assertSessionHasNoErrors();

        expect($this->supplier->kycSubmissions()->first()->status->value)->toBe('under_review');
    });

    it('refuses a selfie when the batch has it off', function () {
        selfieRuleBatch(KycAudience::Supplier, 'disabled');

        $this->get(route('supplier.kyc.create'))->assertInertia(fn (Assert $page) => $page->where('batch.selfie', null));
        $this->post(route('supplier.kyc.evidence.file'), selfieRuleLive())->assertSessionHasErrors('file');

        expect(SupplierKycDocument::query()->count())->toBe(0);
    });

    it('keeps the selfie private to its own Supplier', function () {
        selfieRuleBatch(KycAudience::Supplier, 'required');
        $this->get(route('supplier.kyc.create'));
        $this->post(route('supplier.kyc.evidence.file'), selfieRuleLive())->assertSessionHasNoErrors();

        $document = SupplierKycDocument::query()->sole();

        $this->get(route('supplier.kyc.documents.show', $document))
            ->assertOk()
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');

        // Another Supplier, and a Partner, are turned away.
        auth('supplier')->logout();
        supplierTestSignIn(Supplier::factory()->kycPending()->create());
        $this->get(route('supplier.kyc.documents.show', $document))->assertNotFound();

        auth('supplier')->logout();
        $partner = testBusinessAccount(AccountStatus::KycPending)->owner;
        $status = $this->actingAs($partner)->get(route('supplier.kyc.documents.show', $document))->getStatusCode();

        expect($status)->toBeIn([302, 403, 404]);
    });
});
