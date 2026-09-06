<?php

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Kyc\Models\KycDocumentType;
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
    expect(KycDocumentType::query()->count())->toBe(5)
        ->and(KycDocumentType::query()->active()->count())->toBe(4)
        ->and(KycDocumentType::query()->where('key', 'trade_licence')->first()->scopes)
        ->toHaveCount(1);
});

it('opens the requirement catalogue for the KYC manager', function () {
    $manager = User::query()->where('email', 'kyc@feriwala.test')->firstOrFail();

    $this->actingAs($manager)
        ->get(route('admin.kyc.document-types.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/kyc/document-types')
            ->has('types', 5)
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
