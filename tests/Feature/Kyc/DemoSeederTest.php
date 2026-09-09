<?php

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Package\Models\Package;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The development seeder (§43).
 *
 * Tested because a seeder that has silently stopped working is discovered by
 * somebody trying to review progress, which is the worst moment to find out.
 * These assert the screens can actually be opened with what it creates.
 */

beforeEach(function () {
    $this->seed(DemoSeeder::class);
});

it('seeds a catalogue covering every scoping shape', function () {
    // Global, country-scoped, package-scoped, value-only, and one paused —
    // so every branch of §7.2 resolution has something real to exercise.
    expect(KycDocumentType::query()->count())->toBe(6)
        ->and(KycDocumentType::query()->active()->count())->toBe(5)
        ->and(KycDocumentType::query()->where('key', 'trade_licence')->first()->scopes)
        ->toHaveCount(1)
        ->and(KycDocumentType::query()->where('key', 'company_registration')->first()->scopes->first()->package_public_id)
        ->toBe(Package::query()->where('slug', 'enterprise')->value('public_id'));
});

it('seeds packages for the package-scoped rule to point at', function () {
    // A scope naming a slug that resolves to nothing is refused, so the
    // seeder cannot create one without the catalogue behind it.
    expect(Package::query()->count())->toBe(3)
        ->and(Package::query()->where('slug', 'enterprise')->exists())->toBeTrue();
});

it('opens the requirement catalogue for the KYC manager', function () {
    $manager = User::query()->where('email', 'kyc@feriwala.test')->firstOrFail();

    /*
     * Enrolled here rather than by the seeder. A KYC Manager reads personal
     * documents, so §36 keeps the panel shut until a second factor is set up
     * (P1-18) — and a seeded fake secret would be worse than the redirect,
     * because no authenticator could produce a code for it and the demo login
     * would stop working entirely. Somebody using the demo data enrols once,
     * exactly as a real staff member does. What this test is about is whether
     * the seeded catalogue renders.
     */
    $manager->forceFill([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($manager->refresh())
        ->get(route('admin.kyc.document-types.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/kyc/document-types')
            ->has('types', 6)
            ->where('can.create', true),
        );
});

it('keeps the catalogue away from a reviewer who only decides applications', function () {
    // The §7.2 split, made visible: seeded on purpose so it can be seen in the
    // navigation rather than only read in a policy.
    $reviewer = User::query()->where('email', 'reviewer@feriwala.test')->firstOrFail();

    $this->actingAs($reviewer)->get(route('admin.kyc.index'))->assertOk();
    $this->actingAs($reviewer)
        ->get(route('admin.kyc.document-types.index'))
        ->assertForbidden();
});

it('opens the applicant history for a seeded business owner', function () {
    $owner = BusinessAccount::query()
        ->where('name', 'Mitu Fashion House')
        ->firstOrFail()
        ->owner;

    $this->actingAs($owner)
        ->get(route('kyc.history'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/kyc-history')
            ->has('rounds', 1)
            // The feedback written to them is there…
            ->where('rounds.0.feedback.0.feedback', 'Your ID photo is too dark to read. Please send a clearer picture in daylight.'),
        );
});

it('never shows a seeded applicant the reviewer\'s internal note', function () {
    $owner = BusinessAccount::query()
        ->where('name', 'Mitu Fashion House')
        ->firstOrFail()
        ->owner;

    // …and the note about them is not.
    $this->actingAs($owner)
        ->get(route('kyc.history'))
        ->assertDontSee('watch for a pattern');
});

it('gives every seeded round the requirements it was opened against', function () {
    $account = BusinessAccount::query()->where('name', 'Shanto Enterprise')->firstOrFail();

    expect($account->kycSubmissions()->first()->requirements()->count())
        ->toBeGreaterThan(0);
});

it('refuses to run in production', function () {
    // It creates logins with a known password.
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new DemoSeeder)->run())
        ->toThrow(RuntimeException::class, 'must never run in production');
});
