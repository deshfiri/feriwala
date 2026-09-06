<?php

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycSubmissionRequirement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The requirement catalogue screen (P1-20 frontend, §7.2, §33).
 *
 * Asserted at the response, because a control the server offers is a control a
 * person can press. The rules that matter are which actions are offered to
 * whom, and whether the navigation entry appears at all.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = testPlatformStaff(PlatformRole::SuperAdmin);
});

describe('creating through the form', function () {
    it('creates a requirement with its scope rules in one save', function () {
        // Who a requirement applies to is part of what the requirement is, so
        // the form saves both together rather than leaving a half-configured
        // type behind.
        $this->actingAs($this->admin)
            ->from(route('admin.kyc.document-types.index'))
            ->post(route('admin.kyc.document-types.store'), [
                'key' => 'trade_licence',
                'name' => 'Trade licence',
                'instructions' => 'Current year only.',
                'is_required' => '1',
                'is_active' => '1',
                'requires_file' => '1',
                'requires_value' => '0',
                'accepted_mime_types' => ['application/pdf'],
                'max_size_kb' => 2048,
                'scopes' => [
                    ['package' => null, 'country' => 'bd', 'is_required' => '1'],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $type = KycDocumentType::query()->where('key', 'trade_licence')->firstOrFail();

        expect($type->scopes)->toHaveCount(1)
            // Normalised on the way in, so a lowercase entry still matches.
            ->and($type->scopes->first()->country_code)->toBe('BD')
            ->and($type->scopes->first()->is_required)->toBeTrue();
    });

    it('reports a bad key as a field error rather than a crash', function () {
        $this->actingAs($this->admin)
            ->from(route('admin.kyc.document-types.index'))
            ->post(route('admin.kyc.document-types.store'), [
                'key' => 'Not A Key',
                'name' => 'Bad',
                'is_required' => '1',
                'is_active' => '1',
                'requires_file' => '1',
                'requires_value' => '0',
                'accepted_mime_types' => ['application/pdf'],
                'max_size_kb' => 1024,
            ])
            ->assertSessionHasErrors('key');
    });

    it('refuses a duplicate key', function () {
        KycDocumentType::factory()->key('national_id')->create();

        $this->actingAs($this->admin)
            ->from(route('admin.kyc.document-types.index'))
            ->post(route('admin.kyc.document-types.store'), [
                'key' => 'national_id',
                'name' => 'Another',
                'is_required' => '1',
                'is_active' => '1',
                'requires_file' => '1',
                'requires_value' => '0',
                'accepted_mime_types' => ['application/pdf'],
                'max_size_kb' => 1024,
            ])
            ->assertSessionHasErrors('key');
    });

    it('lets a type keep its own key when edited', function () {
        // The unique rule must ignore the row being saved, or editing a name
        // would fail on the key that row already has.
        $type = KycDocumentType::factory()->key('national_id')->create();

        $this->actingAs($this->admin)
            ->from(route('admin.kyc.document-types.index'))
            ->patch(route('admin.kyc.document-types.update', $type->public_id), [
                'key' => 'national_id',
                'name' => 'National ID card',
                'is_required' => '1',
                'is_active' => '1',
                'requires_file' => '1',
                'requires_value' => '0',
                'accepted_mime_types' => ['application/pdf'],
                'max_size_kb' => 1024,
            ])
            ->assertSessionHasNoErrors();

        expect($type->refresh()->name)->toBe('National ID card');
    });
});

describe('reordering', function () {
    it('saves a new order from the screen', function () {
        $first = KycDocumentType::factory()->create(['sort_order' => 0]);
        $second = KycDocumentType::factory()->create(['sort_order' => 1]);

        $this->actingAs($this->admin)
            ->from(route('admin.kyc.document-types.index'))
            ->post(route('admin.kyc.document-types.reorder'), [
                'order' => [$second->public_id, $first->public_id],
            ])
            ->assertSessionHasNoErrors();

        expect($second->refresh()->sort_order)->toBe(0);
    });

    it('lists requirements in the order applicants will see them', function () {
        KycDocumentType::factory()->create(['name' => 'Second', 'sort_order' => 1]);
        KycDocumentType::factory()->create(['name' => 'First', 'sort_order' => 0]);

        $this->actingAs($this->admin)
            ->get(route('admin.kyc.document-types.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('types.0.name', 'First')
                ->where('types.1.name', 'Second'),
            );
    });
});

describe('what the screen offers', function () {
    it('offers creation to somebody who may configure', function () {
        $this->actingAs($this->admin)
            ->get(route('admin.kyc.document-types.index'))
            ->assertInertia(fn (Assert $page) => $page->where('can.create', true));
    });

    it('offers no edit or delete control on an archived type', function () {
        KycDocumentType::factory()->archived()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.kyc.document-types.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('types.0.is_archived', true)
                ->where('types.0.can.update', false),
            );
    });

    it('says why a used requirement cannot be deleted', function () {
        // The count is what turns "you cannot" into "here is why".
        $type = KycDocumentType::factory()->create();
        $account = testBusinessAccount();

        $submission = $account->kycSubmissions()->create([
            'status' => KycStatus::Approved,
            'round' => 1,
        ]);

        $submission->requirements()->create(
            KycSubmissionRequirement::snapshotOf($type, true)
        );

        $this->actingAs($this->admin)
            ->get(route('admin.kyc.document-types.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('types.0.used_by_rounds', 1)
                ->where('types.0.can.delete', false),
            );
    });

    it('carries the scope rules the editor renders', function () {
        KycDocumentType::factory()->scopedTo(package: 'enterprise', country: 'BD')->create();

        $this->actingAs($this->admin)
            ->get(route('admin.kyc.document-types.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('types.0.scopes.0.package', 'enterprise')
                ->where('types.0.scopes.0.country', 'BD'),
            );
    });
});

describe('navigation', function () {
    it('shows the requirements entry to somebody who may configure', function () {
        $this->actingAs($this->admin)
            ->get(route('admin.kyc.index'))
            ->assertInertia(fn (Assert $page) => expect(
                $page->toArray()['props']['permissions']['kyc.manage_settings']
            )->toBeTrue());
    });

    it('hides it from a reviewer who only decides applications', function () {
        // Shaping the catalogue and deciding one case are different jobs.
        $reviewer = User::factory()->staff()->create();
        $reviewer->givePermissionTo(PermissionCatalogue::name(
            PermissionModule::Kyc,
            PermissionAction::View,
        ));

        $this->actingAs($reviewer)
            ->get(route('admin.kyc.index'))
            ->assertInertia(function (Assert $page) {
                $permissions = $page->toArray()['props']['permissions'];

                expect($permissions['kyc.view'])->toBeTrue()
                    ->and($permissions['kyc.manage_settings'])->toBeFalse();
            });
    });
});
