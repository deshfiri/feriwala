<?php

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Kyc\Actions\ManageKycDocumentTypes;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Kyc\Models\KycSubmissionRequirement;
use App\Domain\Package\Models\Package;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The KYC requirement catalogue (P1-20, §7.2).
 *
 * The rule that shapes everything: a type a submission has referenced is never
 * deleted. Removing it would leave a reviewed round describing a requirement
 * nobody can name, and a decision that cannot be read cannot be defended.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = testPlatformStaff(PlatformRole::SuperAdmin);
});

/**
 * Somebody who configures the catalogue but is not Super Admin.
 *
 * Super Admin passes every policy through `Gate::before`, so a policy guard
 * cannot be observed through one — see the action test below.
 */
function typeTestConfigurer(): User
{
    $user = User::factory()->staff()->create();
    $user->givePermissionTo(PermissionCatalogue::name(
        PermissionModule::Kyc,
        PermissionAction::ManageSettings,
    ));

    return $user;
}

/** A type that a round has actually been opened against. */
function typeTestReferencedType(): KycDocumentType
{
    $type = KycDocumentType::factory()->create();
    $account = testBusinessAccount();

    $submission = KycSubmission::create([
        'business_account_id' => $account->id,
        'status' => KycStatus::Approved,
        'round' => 1,
    ]);

    $submission->requirements()->create(
        KycSubmissionRequirement::snapshotOf($type, true)
    );

    return $type;
}

describe('creating and editing', function () {
    it('creates a type with every configurable field', function () {
        $type = app(ManageKycDocumentTypes::class)->create([
            'key' => 'trade_licence',
            'name' => 'Trade licence',
            'instructions' => 'Current year only.',
            'is_required' => true,
            'is_active' => true,
            'requires_file' => true,
            'requires_value' => false,
            'accepted_mime_types' => ['application/pdf'],
            'max_size_kb' => 2048,
        ], $this->admin);

        expect($type->key)->toBe('trade_licence')
            ->and($type->max_size_kb)->toBe(2048)
            ->and($type->accepted_mime_types)->toBe(['application/pdf']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'kyc.document_type_created']);
    });

    it('appends a new type to the end of the order', function () {
        KycDocumentType::factory()->create(['sort_order' => 7]);

        $type = app(ManageKycDocumentTypes::class)->create([
            'key' => 'later',
            'name' => 'Later',
            'is_required' => false,
            'is_active' => true,
            'requires_file' => true,
            'requires_value' => false,
            'accepted_mime_types' => ['application/pdf'],
            'max_size_kb' => 1024,
        ], $this->admin);

        expect($type->sort_order)->toBe(8);
    });

    it('reorders in one transaction', function () {
        // A half-applied reorder leaves two types claiming a position, and the
        // form silently falls back to insertion order.
        $first = KycDocumentType::factory()->create(['sort_order' => 0]);
        $second = KycDocumentType::factory()->create(['sort_order' => 1]);

        app(ManageKycDocumentTypes::class)->reorder(
            [$second->public_id, $first->public_id],
            $this->admin,
        );

        expect($second->refresh()->sort_order)->toBe(0)
            ->and($first->refresh()->sort_order)->toBe(1);
    });
});

describe('pausing, archiving and deleting', function () {
    it('pauses a type without retiring it', function () {
        $type = KycDocumentType::factory()->create();

        app(ManageKycDocumentTypes::class)->setActive($type, false, $this->admin);

        expect($type->refresh()->is_active)->toBeFalse()
            ->and($type->isArchived())->toBeFalse();
    });

    it('resumes a paused type', function () {
        $type = KycDocumentType::factory()->inactive()->create();

        app(ManageKycDocumentTypes::class)->setActive($type, true, $this->admin);

        expect($type->refresh()->is_active)->toBeTrue();
    });

    it('deactivates as it archives, so the two cannot disagree', function () {
        $type = KycDocumentType::factory()->create();

        app(ManageKycDocumentTypes::class)->archive($type, $this->admin);

        expect($type->refresh()->isArchived())->toBeTrue()
            ->and($type->is_active)->toBeFalse();
    });

    it('will not resurrect an archived type', function () {
        $type = KycDocumentType::factory()->archived()->create();

        expect(fn () => app(ManageKycDocumentTypes::class)->setActive($type, true, $this->admin))
            ->toThrow(InvalidArgumentException::class, 'cannot be reactivated');
    });

    it('will not edit an archived type', function () {
        // It is part of a historical record. Editing it would change what past
        // rounds appear to have asked for.
        $type = KycDocumentType::factory()->archived()->create();

        expect(fn () => app(ManageKycDocumentTypes::class)->update($type, ['name' => 'New'], $this->admin))
            ->toThrow(InvalidArgumentException::class, 'archived');
    });

    it('deletes a type nothing has ever referenced', function () {
        $type = KycDocumentType::factory()->create();

        app(ManageKycDocumentTypes::class)->delete($type, $this->admin);

        $this->assertDatabaseMissing('kyc_document_types', ['id' => $type->id]);
    });

    it('refuses to delete one a submission has referenced', function () {
        $type = typeTestReferencedType();

        expect(fn () => app(ManageKycDocumentTypes::class)->delete($type, $this->admin))
            ->toThrow(InvalidArgumentException::class, 'Archive it instead');

        $this->assertDatabaseHas('kyc_document_types', ['id' => $type->id]);
    });

    it('archives a referenced type instead, keeping the record readable', function () {
        $type = typeTestReferencedType();

        app(ManageKycDocumentTypes::class)->archive($type, $this->admin);

        expect($type->refresh()->isArchived())->toBeTrue();

        // The round still names it, and still knows what it asked for.
        $this->assertDatabaseHas('kyc_submission_requirements', [
            'kyc_document_type_id' => $type->id,
        ]);
    });
});

describe('who may configure it', function () {
    it('is manage-settings, not approve', function () {
        // Shaping the catalogue decides what every future applicant provides;
        // reviewing decides one case.
        $reviewer = testPlatformStaff(PlatformRole::KycManager);
        $type = KycDocumentType::factory()->create();

        expect($reviewer->can('viewAny', KycDocumentType::class))->toBeTrue()
            ->and(User::factory()->staff()->create()->can('update', $type))->toBeFalse();
    });

    it('never offers deletion of a referenced type', function () {
        $type = typeTestReferencedType();

        expect(typeTestConfigurer()->can('delete', $type))->toBeFalse();
    });

    it('never offers editing of an archived type', function () {
        $type = KycDocumentType::factory()->archived()->create();

        expect(typeTestConfigurer()->can('update', $type))->toBeFalse();
    });

    it('refuses even a Super Admin at the action', function () {
        /*
         * Super Admin passes every policy through `Gate::before`, which is
         * deliberate — the grant must not drift out of step with the
         * catalogue. So "a referenced type is never deleted" cannot live in a
         * policy alone: it is an invariant of the record, and the action holds
         * it whatever the caller is permitted to do.
         */
        $type = typeTestReferencedType();

        expect($this->admin->can('delete', $type))->toBeTrue()
            ->and(fn () => app(ManageKycDocumentTypes::class)->delete($type, $this->admin))
            ->toThrow(InvalidArgumentException::class, 'Archive it instead');
    });
});

describe('the admin screen', function () {
    it('lists live and archived types with what blocks deletion', function () {
        KycDocumentType::factory()->create(['name' => 'National ID']);
        typeTestReferencedType();
        KycDocumentType::factory()->archived()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.kyc.document-types.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/kyc/document-types')
                ->has('types', 3)
                ->where('can.create', true),
            );
    });

    it('uses public ids, never database ids', function () {
        $type = KycDocumentType::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.kyc.document-types.index'))
            ->assertInertia(fn (Assert $page) => $page->where('types.0.id', $type->public_id));
    });

    it('turns away somebody without the permission', function () {
        $this->actingAs(User::factory()->staff()->create())
            ->get(route('admin.kyc.document-types.index'))
            ->assertForbidden();
    });

    it('refuses a type that asks for neither a file nor a value', function () {
        // It would appear on the form as an item nobody can satisfy.
        $this->actingAs($this->admin)
            ->from(route('admin.kyc.document-types.index'))
            ->post(route('admin.kyc.document-types.store'), [
                'key' => 'nothing',
                'name' => 'Nothing',
                'is_required' => true,
                'is_active' => true,
                'requires_file' => false,
                'requires_value' => false,
                'accepted_mime_types' => ['application/pdf'],
                'max_size_kb' => 1024,
            ])
            ->assertSessionHasErrors('requires_file');
    });

    it('refuses a scope rule that names nothing', function () {
        // That is the global case, which is what having no scopes means.
        $this->actingAs($this->admin)
            ->from(route('admin.kyc.document-types.index'))
            ->post(route('admin.kyc.document-types.store'), [
                'key' => 'vague',
                'name' => 'Vague',
                'is_required' => true,
                'is_active' => true,
                'requires_file' => true,
                'requires_value' => false,
                'accepted_mime_types' => ['application/pdf'],
                'max_size_kb' => 1024,
                'scopes' => [['package' => null, 'country' => null]],
            ])
            ->assertSessionHasErrors('scopes.0.package');
    });

    it('replaces scope rules wholesale rather than merging them', function () {
        // Merging would make removing a rule impossible through the form that
        // added it.
        //
        // The replacement rule names a real package, because a slug that
        // resolves to nothing is refused — see KycScopeSelectionTest.
        Package::create([
            'slug' => 'enterprise',
            'name' => 'Enterprise',
            'fee_minor' => 500000,
            'currency_code' => 'BDT',
            'is_active' => true,
            'is_public' => true,
        ]);

        $type = KycDocumentType::factory()->scopedTo(country: 'BD')->create();

        $this->actingAs($this->admin)
            ->from(route('admin.kyc.document-types.index'))
            ->patch(route('admin.kyc.document-types.update', $type->public_id), [
                'key' => $type->key,
                'name' => $type->name,
                'is_required' => true,
                'is_active' => true,
                'requires_file' => true,
                'requires_value' => false,
                'accepted_mime_types' => ['application/pdf'],
                'max_size_kb' => 1024,
                'scopes' => [['package' => 'enterprise', 'country' => null]],
            ]);

        $scopes = $type->refresh()->scopes;

        expect($scopes)->toHaveCount(1)
            ->and($scopes->first()->package_slug)->toBe('enterprise')
            ->and($scopes->first()->country_code)->toBeNull();
    });

    it('turns a refused deletion into a form error, not a 500', function () {
        $type = typeTestReferencedType();

        $this->actingAs($this->admin)
            ->from(route('admin.kyc.document-types.index'))
            ->delete(route('admin.kyc.document-types.destroy', $type->public_id))
            ->assertSessionHasErrors('type');

        $this->assertDatabaseHas('kyc_document_types', ['id' => $type->id]);
    });

    it('turns away a configurer the policy refuses', function () {
        $type = typeTestReferencedType();

        $this->actingAs(typeTestConfigurer())
            ->delete(route('admin.kyc.document-types.destroy', $type->public_id))
            ->assertForbidden();
    });
});
