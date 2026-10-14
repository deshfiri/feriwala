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
 * The applicant's side of a KYC batch (D30, commit 3): the grouped form, the
 * evidence endpoints, and sending it off — for Partners and for Suppliers, each
 * on their own guard and their own data.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('kyc');
    Notification::fake();
});

/**
 * @param  array<string, array{0: string, 1?: bool}>  $slots
 */
function applicantFlowRequirement(string $key, array $slots, string $name = ''): KycDocumentType
{
    // One requirement can sit in a Partner batch and a Supplier batch alike.
    $existing = KycDocumentType::query()->where('key', $key)->first();

    if ($existing !== null) {
        return $existing;
    }

    $type = KycDocumentType::factory()->key($key)->create([
        'name' => $name !== '' ? $name : ucfirst(str_replace('_', ' ', $key)),
        'name_bn' => 'বাংলা '.$key,
        'audience' => KycAudience::Both,
    ]);

    $order = 0;

    foreach ($slots as $slotKey => $spec) {
        $type->slots()->create([
            'key' => $slotKey,
            'label' => ucfirst($slotKey),
            'is_required' => $spec[1] ?? true,
            'evidence_type' => $spec[0],
            'accepted_mime_types' => in_array($spec[0], ['value', 'declaration'], true) ? null : ['image/jpeg', 'image/png'],
            'max_size_kb' => in_array($spec[0], ['value', 'declaration'], true) ? null : 2048,
            'is_sensitive' => $spec[0] === 'value',
            'sort_order' => $order++,
        ]);
    }

    return $type;
}

/** An identity group (any one of NID, passport), a TIN, and an optional selfie. */
function applicantFlowBatch(KycAudience $audience, string $selfie = 'disabled'): KycBatch
{
    $batch = KycBatch::create([
        'key' => 'applicant_flow_'.$audience->value,
        'name' => 'Standard verification',
        'name_bn' => 'মান যাচাই',
        'audience' => $audience,
        'is_default' => true,
        'selfie_mode' => $selfie,
    ]);

    $identity = KycBatchGroup::create(['kyc_batch_id' => $batch->id, 'key' => 'identity', 'name' => 'Identity', 'completion_rule' => KycCompletionRule::Any, 'sort_order' => 0]);
    $identity->items()->create(['kyc_document_type_id' => applicantFlowRequirement('national_id', ['front' => ['image'], 'back' => ['image']], 'National ID')->id, 'sort_order' => 0]);
    $identity->items()->create(['kyc_document_type_id' => applicantFlowRequirement('passport', ['bio_page' => ['image']], 'Passport')->id, 'sort_order' => 1]);

    $business = KycBatchGroup::create(['kyc_batch_id' => $batch->id, 'key' => 'business', 'name' => 'Business', 'completion_rule' => KycCompletionRule::All, 'sort_order' => 1]);
    $business->items()->create(['kyc_document_type_id' => applicantFlowRequirement('tin', ['number' => ['value']], 'TIN')->id, 'sort_order' => 0]);

    return $batch;
}

function applicantFlowImage(string $name = 'photo.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name)->size(100);
}

describe('a Partner', function () {
    beforeEach(function () {
        applicantFlowBatch(KycAudience::Account, 'required');

        $this->account = testBusinessAccount(AccountStatus::KycPending);
        $this->applicant = $this->account->owner;
    });

    it('sees the batch as groups, with what each asks and how far along they are', function () {
        $this->actingAs($this->applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('onboarding/kyc')
                ->where('batch.name', 'Standard verification')
                ->where('batch.groups.0.key', 'identity')
                ->where('batch.groups.0.choose', true)
                ->where('batch.groups.0.needed', 1)
                ->where('batch.groups.0.options.0.name', 'National ID')
                ->where('batch.groups.0.options.0.slots.0.label', 'Front')
                ->where('batch.groups.0.options.0.slots.0.max_kb', 2048)
                ->where('batch.groups.1.choose', false)
                ->where('batch.selfie.required', true)
                ->where('batch.complete', false)
                // The earlier flat list is not used for a round on a batch.
                ->where('requirements', []));
    });

    it('is shown the batch in Bangla when that is their language', function () {
        $this->applicant->forceFill(['locale' => 'bn'])->save();
        app()->setLocale('bn');

        $this->actingAs($this->applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('batch.name', 'মান যাচাই')
                ->where('batch.groups.0.options.0.name', 'বাংলা national_id'));
    });

    it('saves a file into a slot as a draft, and sees it on the next visit', function () {
        $this->actingAs($this->applicant)->get(route('kyc.create'));

        $this->actingAs($this->applicant)
            ->post(route('kyc.evidence.file'), ['requirement' => 'national_id', 'slot' => 'front', 'file' => applicantFlowImage('front.jpg')])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('batch.groups.0.options.0.slots.0.current.name', 'front.jpg')
                ->where('batch.groups.0.options.0.chosen', true)
                ->where('batch.groups.0.options.1.chosen', false)
                // Half of a two-file option is not a finished option.
                ->where('batch.groups.0.done', 0));

        expect(KycDocument::query()->sole()->slot_key)->toBe('front');
    });

    it('takes a typed value, shows it masked, and never echoes it back in full', function () {
        $this->actingAs($this->applicant)->get(route('kyc.create'));

        $this->actingAs($this->applicant)
            ->post(route('kyc.evidence.value'), ['requirement' => 'tin', 'slot' => 'number', 'value' => '123456789012'])
            ->assertSessionHasNoErrors();

        $response = $this->actingAs($this->applicant)->get(route('kyc.create'));

        $response->assertInertia(fn (Assert $page) => $page->where('batch.groups.1.options.0.slots.0.current.value_preview', '••••••••9012'));

        expect($response->getContent())->not->toContain('123456789012');
    });

    it('chooses an option before uploading, and removes a file', function () {
        $this->actingAs($this->applicant)->get(route('kyc.create'));

        $this->actingAs($this->applicant)
            ->post(route('kyc.evidence.choose'), ['group' => 'identity', 'requirement' => 'passport'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->applicant)->post(route('kyc.evidence.file'), ['requirement' => 'passport', 'slot' => 'bio_page', 'file' => applicantFlowImage()]);
        $this->actingAs($this->applicant)->post(route('kyc.evidence.remove'), ['requirement' => 'passport', 'slot' => 'bio_page'])->assertSessionHasNoErrors();

        expect(KycDocument::query()->count())->toBe(0);
    });

    it('is told why a file is refused, in words, against the field', function () {
        $this->actingAs($this->applicant)->get(route('kyc.create'));

        $this->actingAs($this->applicant)
            ->post(route('kyc.evidence.file'), ['requirement' => 'passport', 'slot' => 'bio_page', 'file' => UploadedFile::fake()->create('x.pdf', 50, 'application/pdf')])
            ->assertSessionHasErrors('file');

        $this->actingAs($this->applicant)
            ->post(route('kyc.evidence.file'), ['requirement' => 'passport', 'slot' => 'nonsense', 'file' => applicantFlowImage()])
            ->assertSessionHasErrors('file');

        expect(KycDocument::query()->count())->toBe(0);
    });

    it('keeps only safe facts about a live selfie, and refuses a picked file for it', function () {
        $this->actingAs($this->applicant)->get(route('kyc.create'));

        $this->actingAs($this->applicant)
            ->post(route('kyc.evidence.file'), ['requirement' => '__selfie', 'slot' => 'capture', 'file' => applicantFlowImage('s.jpg')])
            ->assertSessionHasErrors('file');

        $this->actingAs($this->applicant)
            ->withHeader('User-Agent', str_repeat('A', 400))
            ->post(route('kyc.evidence.file'), [
                'requirement' => '__selfie',
                'slot' => 'capture',
                'file' => applicantFlowImage('s.jpg'),
                'captured_live' => 1,
                'capture_meta' => ['width' => 640, 'height' => 480, 'facing' => 'user', 'face_vector' => '0.12,0.98', 'location' => '23.7,90.4'],
            ])
            ->assertSessionHasNoErrors();

        $meta = KycDocument::query()->sole()->capture_meta;

        // The frame's size and which camera — and no browser, device, face or place.
        expect($meta)->toEqualCanonicalizing(['width' => 640, 'height' => 480, 'facing' => 'user']);
    });

    it('cannot send the application off until every group is satisfied, and then must confirm', function () {
        $this->actingAs($this->applicant)->get(route('kyc.create'));

        $this->actingAs($this->applicant)->post(route('kyc.submit'), ['confirm' => 1])->assertSessionHasErrors('submission');

        foreach ([['passport', 'bio_page']] as [$requirement, $slot]) {
            $this->actingAs($this->applicant)->post(route('kyc.evidence.file'), compact('requirement', 'slot') + ['file' => applicantFlowImage()]);
        }

        $this->actingAs($this->applicant)->post(route('kyc.evidence.value'), ['requirement' => 'tin', 'slot' => 'number', 'value' => '123456789012']);
        $this->actingAs($this->applicant)->post(route('kyc.evidence.file'), ['requirement' => '__selfie', 'slot' => 'capture', 'file' => applicantFlowImage('s.jpg'), 'captured_live' => 1]);

        // Complete, but not confirmed.
        $this->actingAs($this->applicant)->post(route('kyc.submit'))->assertSessionHasErrors('confirm');

        expect(KycSubmission::query()->sole()->status)->toBe(KycStatus::Draft);

        $this->actingAs($this->applicant)->post(route('kyc.submit'), ['confirm' => 1])->assertRedirect(route('onboarding.status'));

        expect(KycSubmission::query()->sole()->status)->toBe(KycStatus::Submitted);
    });

    it('can no longer change anything once submitted', function () {
        $this->actingAs($this->applicant)->get(route('kyc.create'));
        KycSubmission::query()->sole()->forceFill(['status' => KycStatus::Submitted])->save();

        $this->actingAs($this->applicant)
            ->post(route('kyc.evidence.file'), ['requirement' => 'passport', 'slot' => 'bio_page', 'file' => applicantFlowImage()])
            ->assertSessionHasErrors('file');
    });

    it('is refused at the earlier endpoint, which only understands the flat list', function () {
        $this->actingAs($this->applicant)->get(route('kyc.create'));

        $this->actingAs($this->applicant)
            ->post(route('kyc.documents.store'), ['document_type' => 'tin', 'value' => '123'])
            ->assertSessionHasErrors('file');
    });

    it('can only ever touch their own account\'s round', function () {
        $other = testBusinessAccount(AccountStatus::KycPending)->owner;

        $this->actingAs($this->applicant)->get(route('kyc.create'));
        $this->actingAs($other)->get(route('kyc.create'));

        $this->actingAs($other)->post(route('kyc.evidence.file'), ['requirement' => 'passport', 'slot' => 'bio_page', 'file' => applicantFlowImage('other.jpg')]);

        $mine = KycSubmission::query()->where('business_account_id', $this->account->id)->sole();

        expect($mine->documents()->count())->toBe(0)
            ->and(KycDocument::query()->sole()->kyc_submission_id)->not->toBe($mine->id);
    });

    it('is turned away when signed out', function () {
        auth()->logout();

        $this->post(route('kyc.evidence.file'), ['requirement' => 'passport', 'slot' => 'bio_page'])->assertRedirect(route('login'));
    });
});

describe('a Supplier', function () {
    beforeEach(function () {
        applicantFlowBatch(KycAudience::Supplier);

        $this->supplier = supplierTestSignIn(Supplier::factory()->kycPending()->create());
    });

    it('sees the same grouped form, from the Supplier batch', function () {
        $this->get(route('supplier.kyc.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('supplier/kyc/index')
                ->where('batch.name', 'Standard verification')
                ->where('batch.groups.0.rule', 'any')
                ->where('batch.groups.0.options.1.name', 'Passport')
                ->where('batch.selfie', null)
                ->where('batch.complete', false));
    });

    it('saves evidence into the Supplier\'s own tables only', function () {
        $this->get(route('supplier.kyc.create'));

        $this->post(route('supplier.kyc.evidence.file'), ['requirement' => 'national_id', 'slot' => 'front', 'file' => applicantFlowImage('front.jpg')])
            ->assertSessionHasNoErrors();
        $this->post(route('supplier.kyc.evidence.file'), ['requirement' => 'national_id', 'slot' => 'back', 'file' => applicantFlowImage('back.jpg')])
            ->assertSessionHasNoErrors();

        expect(SupplierKycDocument::query()->count())->toBe(2)
            ->and(KycDocument::query()->count())->toBe(0);

        $this->get(route('supplier.kyc.create'))
            ->assertInertia(fn (Assert $page) => $page->where('batch.groups.0.done', 1)->where('batch.groups.0.satisfied', true));
    });

    it('cannot send it off until complete and confirmed, then is under review', function () {
        $this->get(route('supplier.kyc.create'));

        $this->post(route('supplier.kyc.submit'), ['confirm' => 1])->assertSessionHasErrors('submission');

        $this->post(route('supplier.kyc.evidence.file'), ['requirement' => 'passport', 'slot' => 'bio_page', 'file' => applicantFlowImage()]);
        $this->post(route('supplier.kyc.evidence.value'), ['requirement' => 'tin', 'slot' => 'number', 'value' => '123456789012']);

        $this->post(route('supplier.kyc.submit'))->assertSessionHasErrors('confirm');
        $this->post(route('supplier.kyc.submit'), ['confirm' => 1])->assertSessionHasNoErrors();

        expect($this->supplier->kycSubmissions()->first()->status->value)->toBe('under_review');
    });

    it('is refused at the earlier endpoint', function () {
        $this->get(route('supplier.kyc.create'));

        $this->post(route('supplier.kyc.documents.store'), ['document_type' => 'tin', 'value' => '123'])->assertSessionHasErrors('file');
    });

    it('is kept apart from Partner screens, and a Partner from theirs', function () {
        // The Supplier's session is not a Partner's.
        $this->post(route('kyc.evidence.file'), ['requirement' => 'passport', 'slot' => 'bio_page'])->assertRedirect(route('login'));

        // And a Partner is not a Supplier.
        auth('supplier')->logout();
        $partner = testBusinessAccount(AccountStatus::KycPending)->owner;

        $this->actingAs($partner)->post(route('supplier.kyc.evidence.file'), ['requirement' => 'passport', 'slot' => 'bio_page'])
            ->assertRedirect();

        expect(SupplierKycDocument::query()->count())->toBe(0);
    });
});

describe('the Supplier and Partner batches never mix', function () {
    it('gives a Partner only a batch for Partners, even when a Supplier one is the default for suppliers', function () {
        applicantFlowBatch(KycAudience::Supplier);
        $partnerBatch = applicantFlowBatch(KycAudience::Account);
        $partnerBatch->groups()->where('key', 'business')->delete();

        $partner = testBusinessAccount(AccountStatus::KycPending)->owner;

        $this->actingAs($partner)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page->has('batch.groups', 1));
    });
});
