<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycDocumentTypeScope;
use App\Domain\Package\Models\Package;
use App\Support\Localization\Countries;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * What a scope rule may name (§7.2).
 *
 * Neither dimension is free text. A slug resolving to no package, or a code
 * that is not a country we serve, produces a rule that looks configured and
 * matches nobody — and nothing says so until an applicant is asked for the
 * wrong documents.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = testPlatformStaff(PlatformRole::SuperAdmin);
});

function scopeTestPackage(string $name = 'Enterprise'): Package
{
    return Package::create([
        'slug' => Str::slug($name),
        'name' => $name,
        'fee_minor' => 500000,
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);
}

/**
 * @param  array<int, array<string, mixed>>  $scopes
 * @return array<string, mixed>
 */
function scopeTestPayload(array $scopes = []): array
{
    return [
        'key' => 'trade_licence',
        'name' => 'Trade licence',
        'is_required' => '1',
        'is_active' => '1',
        'requires_file' => '1',
        'requires_value' => '0',
        'accepted_mime_types' => ['application/pdf'],
        'max_size_kb' => 2048,
        'scopes' => $scopes,
    ];
}

describe('package scoping', function () {
    it('accepts an id that resolves to a package', function () {
        $package = scopeTestPackage();

        $this->actingAs($this->admin)
            ->from(route('admin.kyc.document-types.index'))
            ->post(route('admin.kyc.document-types.store'), scopeTestPayload([
                ['package' => $package->public_id, 'country' => null, 'is_required' => '1'],
            ]))
            ->assertSessionHasNoErrors();

        expect(KycDocumentTypeScope::query()->where('package_public_id', $package->public_id)->exists())
            ->toBeTrue();
    });

    it('survives the package slug being renamed', function () {
        /*
         * The reason a rule keys on the public id rather than the slug. A slug
         * is a routing decision; a rule keyed on one is silently orphaned the
         * day somebody renames a URL, and nothing says so until an applicant
         * is asked for the wrong documents.
         */
        $package = scopeTestPackage();

        $type = KycDocumentType::factory()->scopedTo(package: $package->public_id)->create();

        $package->forceFill(['slug' => 'enterprise-2027'])->save();

        expect($type->refresh()->appliesTo($package->public_id, null))->toBeTrue();
    });

    it('refuses an id that resolves to nothing', function () {
        // The failure this guards: a value saved as configuration, matching
        // nobody, discovered when an applicant is asked for the wrong papers.
        scopeTestPackage();

        $this->actingAs($this->admin)
            ->from(route('admin.kyc.document-types.index'))
            ->post(route('admin.kyc.document-types.store'), scopeTestPayload([
                ['package' => '01NOTAREALPACKAGEID000000', 'country' => null],
            ]))
            ->assertSessionHasErrors('scopes.0.package');

        expect(KycDocumentType::query()->where('key', 'trade_licence')->exists())
            ->toBeFalse();
    });

    it('refuses any package rule while no packages exist', function () {
        // P1-32 has not been built. Nothing resolves, so nothing is accepted —
        // rather than storing a rule that will orphan the moment it is read.
        $this->actingAs($this->admin)
            ->from(route('admin.kyc.document-types.index'))
            ->post(route('admin.kyc.document-types.store'), scopeTestPayload([
                ['package' => 'anything', 'country' => null],
            ]))
            ->assertSessionHasErrors('scopes.0.package');
    });

    it('refuses an id belonging to an archived package', function () {
        // Archived is gone as far as new scoping is concerned.
        $package = scopeTestPackage();
        $package->delete();

        $this->actingAs($this->admin)
            ->from(route('admin.kyc.document-types.index'))
            ->post(route('admin.kyc.document-types.store'), scopeTestPayload([
                ['package' => $package->public_id, 'country' => null],
            ]))
            ->assertSessionHasErrors('scopes.0.package');
    });

    it('leaves no orphaned package rules behind', function () {
        // The invariant, asserted directly: every stored package id resolves.
        $package = scopeTestPackage();

        $this->actingAs($this->admin)
            ->post(route('admin.kyc.document-types.store'), scopeTestPayload([
                ['package' => $package->public_id, 'country' => null],
            ]));

        $orphans = KycDocumentTypeScope::query()
            ->whereNotNull('package_public_id')
            ->whereNotIn('package_public_id', Package::query()->pluck('public_id'))
            ->count();

        expect($orphans)->toBe(0);
    });
});

describe('country scoping', function () {
    it('accepts a supported country, however it was typed', function () {
        $this->actingAs($this->admin)
            ->from(route('admin.kyc.document-types.index'))
            ->post(route('admin.kyc.document-types.store'), scopeTestPayload([
                ['package' => null, 'country' => 'bd'],
            ]))
            ->assertSessionHasNoErrors();

        expect(KycDocumentTypeScope::query()->first()->country_code)->toBe('BD');
    });

    it('refuses a code that is not a country we serve', function () {
        // "Bangladsh" looks configured and matches nobody.
        $this->actingAs($this->admin)
            ->from(route('admin.kyc.document-types.index'))
            ->post(route('admin.kyc.document-types.store'), scopeTestPayload([
                ['package' => null, 'country' => 'ZZ'],
            ]))
            ->assertSessionHasErrors('scopes.0.country');
    });

    it('still refuses a rule naming neither dimension', function () {
        $this->actingAs($this->admin)
            ->from(route('admin.kyc.document-types.index'))
            ->post(route('admin.kyc.document-types.store'), scopeTestPayload([
                ['package' => null, 'country' => null],
            ]))
            ->assertSessionHasErrors('scopes.0.package');
    });
});

describe('the supported country list', function () {
    it('puts the home market first', function () {
        $options = app(Countries::class)->options();

        expect($options[0]['value'])->toBe('BD')
            ->and($options[0]['label'])->toBe('Bangladesh');
    });

    it('answers about a code however it was written', function () {
        $countries = app(Countries::class);

        expect($countries->selectable('bd'))->toBeTrue()
            ->and($countries->selectable(' BD '))->toBeTrue()
            ->and($countries->selectable('ZZ'))->toBeFalse()
            ->and($countries->nameFor('in'))->toBe('India');
    });
});

describe('retiring a market', function () {
    /*
     * The rule that makes a code permanent: a country Feriwala stops selling
     * into disappears from every picker and stays readable everywhere else.
     * Deleting the configuration entry instead would turn every address, scope
     * rule and historical record naming it into data nothing can resolve —
     * silently, with no error anywhere.
     */
    beforeEach(function () {
        config([
            'countries.supported' => ['BD' => 'Bangladesh'],
            'countries.retired' => ['LK' => 'Sri Lanka'],
        ]);
    });

    it('offers a retired market to nobody', function () {
        $countries = app(Countries::class);

        expect($countries->selectable('LK'))->toBeFalse()
            ->and(collect($countries->options())->pluck('value'))->not->toContain('LK');
    });

    it('keeps a retired code resolvable, with its name', function () {
        $countries = app(Countries::class);

        expect($countries->resolves('LK'))->toBeTrue()
            ->and($countries->nameFor('lk'))->toBe('Sri Lanka');
    });

    it('still answers no for a code that was never offered at all', function () {
        expect(app(Countries::class)->resolves('ZZ'))->toBeFalse();
    });

    it('refuses a new scope rule naming a retired market', function () {
        // Nothing new is scoped there…
        $this->actingAs($this->admin)
            ->from(route('admin.kyc.document-types.index'))
            ->post(route('admin.kyc.document-types.store'), scopeTestPayload([
                ['package' => null, 'country' => 'LK'],
            ]))
            ->assertSessionHasErrors('scopes.0.country');
    });

    it('leaves a rule written before the market closed working', function () {
        // …and everything already scoped there keeps matching.
        $type = KycDocumentType::factory()->scopedTo(country: 'LK')->create();

        expect($type->appliesTo(null, 'LK'))->toBeTrue()
            ->and($type->appliesTo(null, 'BD'))->toBeFalse();
    });
});

describe('what the screen is given', function () {
    it('sends an empty package list while none exist', function () {
        // The screen reads this as "P1-32 is not built" and shows the
        // dependency note instead of a control.
        $this->actingAs($this->admin)
            ->get(route('admin.kyc.document-types.index'))
            ->assertInertia(fn (Assert $page) => $page->has('packages', 0));
    });

    it('sends the packages keyed by their immutable id', function () {
        scopeTestPackage('Starter');
        $growth = scopeTestPackage('Growth');

        $this->actingAs($this->admin)
            ->get(route('admin.kyc.document-types.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('packages', 2)
                ->where('packages.0.label', 'Growth')
                ->where('packages.0.value', $growth->public_id),
            );
    });

    it('sends the countries a rule may name', function () {
        $this->actingAs($this->admin)
            ->get(route('admin.kyc.document-types.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('countries.0.value', 'BD')
                ->has('countries', count(app(Countries::class)->codes())),
            );
    });
});
