<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Kyc\Models\KycBatch;
use App\Domain\Kyc\Models\KycDocument;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierKycDocument;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StandardKycBatchSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The Identity group is "any one": the applicant picks National ID, Passport or
 * Driving Licence and completes only that one. These pin the data (rule, options,
 * slots) and what completion means for each choice, on the seeded Standard KYC
 * batch, for a Partner and for a Supplier.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    // The example batch is published on behalf of a Super Admin.
    testPlatformStaff(PlatformRole::SuperAdmin);
    $this->seed(StandardKycBatchSeeder::class);
    Storage::fake('kyc');
    Notification::fake();

    $this->batch = KycBatch::query()->where('key', 'standard_kyc')->sole();
    $this->batch->forceFill(['is_active' => true, 'is_default' => true])->save();
});

/**
 * The slot keys of one option of the seeded batch, in order.
 *
 * @return array{requirement: string, slots: array<int, string>}
 */
function identityDropdownOption(string $name): array
{
    $type = KycDocumentType::query()->where('name', $name)->whereNotNull('kyc_batch_id')->sole();

    return ['requirement' => $type->key, 'slots' => $type->slots()->orderBy('sort_order')->pluck('key')->all()];
}

function identityDropdownImage(string $name = 'photo.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name)->size(100);
}

/** Uploads one slot of an option as the signed-in Partner. */
function identityDropdownPartnerUpload(mixed $test, mixed $applicant, array $option, int $slot): void
{
    $test->actingAs($applicant)->post(route('kyc.evidence.file'), [
        'requirement' => $option['requirement'],
        'slot' => $option['slots'][$slot],
        'file' => identityDropdownImage("slot{$slot}.jpg"),
    ])->assertSessionHasNoErrors();
}

describe('the seeded Identity group', function () {
    it('is "any one", with no minimum, and counts options rather than slots', function () {
        $identity = $this->batch->groups()->where('key', 'identity_verification')->sole();

        expect($identity->completion_rule->value)->toBe('any')
            ->and($identity->minimum_required)->toBeNull()
            ->and($identity->selector_label)->toBe('Select identification document')
            ->and($identity->items()->count())->toBe(3);
    });

    it('offers National ID with two slots, Passport with one and Driving Licence with two', function () {
        $nid = identityDropdownOption('National ID');
        $passport = identityDropdownOption('Passport');
        $licence = identityDropdownOption('Driving Licence');

        expect($nid['slots'])->toHaveCount(2)
            ->and($passport['slots'])->toHaveCount(1)
            ->and($licence['slots'])->toHaveCount(2)
            // The National ID and Licence slots are different rows, even if labelled alike.
            ->and(
                KycDocumentType::query()->whereIn('key', [$nid['requirement'], $licence['requirement']])
                    ->with('slots')->get()->flatMap->slots->pluck('id')->unique()->count()
            )->toBe(4);
    });
});

describe('a Partner', function () {
    beforeEach(function () {
        $this->applicant = testBusinessAccount(AccountStatus::KycPending)->owner;
        $this->nid = identityDropdownOption('National ID');
        $this->passport = identityDropdownOption('Passport');
        $this->licence = identityDropdownOption('Driving Licence');

        $this->actingAs($this->applicant)->get(route('kyc.create'));
    });

    it('is shown the dropdown label, nothing chosen, and no option reported missing', function () {
        $response = $this->actingAs($this->applicant)->get(route('kyc.create'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('batch.groups.0.selector_label', 'Select identification document')
            ->where('batch.groups.0.selected', [])
            ->where('batch.groups.0.satisfied', false)
            ->where('batch.groups.0.options.0.chosen', false));

        // Asked to choose, not told that three documents are outstanding.
        $missing = json_encode($response->viewData('page')['props']['batch']['missing']);

        expect($missing)->not->toContain('Passport')
            ->and($missing)->not->toContain('Driving Licence')
            ->and($missing)->not->toContain('National ID');
    });

    it('completes Identity with a Passport alone', function () {
        identityDropdownPartnerUpload($this, $this->applicant, $this->passport, 0);

        $this->actingAs($this->applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('batch.groups.0.satisfied', true)
                ->where('batch.groups.0.done', 1)
                ->where('batch.groups.0.selected', [$this->passport['requirement']]));
    });

    it('completes Identity with National ID front and back', function () {
        identityDropdownPartnerUpload($this, $this->applicant, $this->nid, 0);
        identityDropdownPartnerUpload($this, $this->applicant, $this->nid, 1);

        $this->actingAs($this->applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('batch.groups.0.satisfied', true)
                ->where('batch.groups.0.options.0.complete', true));
    });

    it('does not complete Identity with only the National ID front, and names only its missing back', function () {
        identityDropdownPartnerUpload($this, $this->applicant, $this->nid, 0);

        $response = $this->actingAs($this->applicant)->get(route('kyc.create'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('batch.groups.0.satisfied', false)
            ->where('batch.groups.0.options.0.provided', 1)
            ->where('batch.groups.0.options.0.total', 2));

        $missing = json_encode($response->viewData('page')['props']['batch']['missing']);

        // The Passport and the Licence are not owed, so neither is mentioned.
        expect($missing)->not->toContain('Passport')->and($missing)->not->toContain('Driving');

        $this->actingAs($this->applicant)->post(route('kyc.submit'), ['confirm' => 1])->assertSessionHasErrors('submission');
        expect(KycSubmission::query()->sole()->status->value)->toBe('draft');
    });

    it('completes Identity with Driving Licence front and back', function () {
        identityDropdownPartnerUpload($this, $this->applicant, $this->licence, 0);
        identityDropdownPartnerUpload($this, $this->applicant, $this->licence, 1);

        $this->actingAs($this->applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('batch.groups.0.satisfied', true)
                ->where('batch.groups.0.options.2.complete', true));
    });

    it('keeps Business Verification independent of a complete Identity group', function () {
        identityDropdownPartnerUpload($this, $this->applicant, $this->passport, 0);

        $this->actingAs($this->applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('batch.groups.0.satisfied', true)
                ->where('batch.groups.1.satisfied', false)
                ->where('batch.groups.1.done', 0)
                ->where('batch.complete', false));

        $this->actingAs($this->applicant)->post(route('kyc.submit'), ['confirm' => 1])->assertSessionHasErrors('submission');
    });

    it('moves to another document by clearing only the unsent files of the one left', function () {
        identityDropdownPartnerUpload($this, $this->applicant, $this->nid, 0);

        expect(KycDocument::query()->count())->toBe(1);

        $this->actingAs($this->applicant)
            ->post(route('kyc.evidence.choose'), ['group' => 'identity_verification', 'requirement' => $this->passport['requirement']])
            ->assertSessionHasNoErrors();

        expect(KycDocument::query()->count())->toBe(0);

        $this->actingAs($this->applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('batch.groups.0.selected', [$this->passport['requirement']])
                ->where('batch.groups.0.options.0.has_draft', false));
    });

    it('cannot walk away from an option once it has been sent', function () {
        identityDropdownPartnerUpload($this, $this->applicant, $this->nid, 0);

        $round = KycSubmission::query()->sole();
        $round->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();

        $this->actingAs($this->applicant)->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('batch.groups.0.options.0.locked', true)
                ->where('batch.groups.0.options.1.locked', false));

        $this->actingAs($this->applicant)
            ->post(route('kyc.evidence.choose'), ['group' => 'identity_verification', 'requirement' => $this->passport['requirement']]);

        // The submitted evidence is still there.
        expect(KycDocument::query()->count())->toBe(1);
    });
});

describe('a Supplier', function () {
    beforeEach(function () {
        $this->supplier = supplierTestSignIn(Supplier::factory()->kycPending()->create());
        $this->licence = identityDropdownOption('Driving Licence');
        $this->passport = identityDropdownOption('Passport');

        $this->get(route('supplier.kyc.create'));
    });

    it('is shown the same dropdown, with nothing chosen', function () {
        $this->get(route('supplier.kyc.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('batch.groups.0.selector_label', 'Select identification document')
                ->where('batch.groups.0.selected', [])
                ->has('batch.groups.0.options', 3));
    });

    it('completes Identity with Driving Licence front and back, and not with the front alone', function () {
        $this->post(route('supplier.kyc.evidence.file'), [
            'requirement' => $this->licence['requirement'], 'slot' => $this->licence['slots'][0], 'file' => identityDropdownImage(),
        ])->assertSessionHasNoErrors();

        $this->get(route('supplier.kyc.create'))
            ->assertInertia(fn (Assert $page) => $page->where('batch.groups.0.satisfied', false));

        $this->post(route('supplier.kyc.evidence.file'), [
            'requirement' => $this->licence['requirement'], 'slot' => $this->licence['slots'][1], 'file' => identityDropdownImage('back.jpg'),
        ])->assertSessionHasNoErrors();

        $this->get(route('supplier.kyc.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('batch.groups.0.satisfied', true)
                ->where('batch.groups.1.satisfied', false));

        expect(SupplierKycDocument::query()->count())->toBe(2)->and(KycDocument::query()->count())->toBe(0);
    });
});
