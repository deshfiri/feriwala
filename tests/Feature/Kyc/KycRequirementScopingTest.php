<?php

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Kyc\Actions\CaptureRoundRequirements;
use App\Domain\Kyc\Actions\OpenKycDraft;
use App\Domain\Kyc\Actions\SubmitKyc;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Kyc\Queries\ApplicableRequirements;

/*
 * Package and country scoping, and the snapshot that freezes it (P1-21, §7.2).
 *
 * Two rules do the work here. Resolution is **most specific first**, with
 * country outranking package because a country rule is usually statutory and a
 * commercial plan must not waive a regulator. And a round keeps what it was
 * opened against, so editing configuration cannot rewrite what a submitted
 * round was judged on.
 */

/** An account whose owner is in `$country`. */
function scopingTestAccount(string $country = 'BD'): BusinessAccount
{
    $account = testBusinessAccount();
    $account->owner->forceFill(['country' => $country])->save();

    return $account->refresh();
}

describe('resolution priority', function () {
    it('applies a type with no scopes to everyone — the safe global fallback', function () {
        $type = KycDocumentType::factory()->create();

        expect($type->appliesTo('enterprise', 'BD'))->toBeTrue()
            ->and($type->appliesTo(null, null))->toBeTrue();
    });

    it('applies a country rule only in that country', function () {
        $type = KycDocumentType::factory()->scopedTo(country: 'BD')->create();

        expect($type->appliesTo(null, 'BD'))->toBeTrue()
            ->and($type->appliesTo(null, 'IN'))->toBeFalse();
    });

    it('applies a package rule only on that package', function () {
        $type = KycDocumentType::factory()->scopedTo(package: 'enterprise')->create();

        expect($type->appliesTo('enterprise', 'BD'))->toBeTrue()
            ->and($type->appliesTo('starter', 'BD'))->toBeFalse();
    });

    it('applies a package-and-country rule only when both hold', function () {
        $type = KycDocumentType::factory()
            ->scopedTo(package: 'enterprise', country: 'BD')
            ->create();

        expect($type->appliesTo('enterprise', 'BD'))->toBeTrue()
            ->and($type->appliesTo('enterprise', 'IN'))->toBeFalse()
            ->and($type->appliesTo('starter', 'BD'))->toBeFalse();
    });

    it('matches a country regardless of case', function () {
        $type = KycDocumentType::factory()->scopedTo(country: 'BD')->create();

        expect($type->appliesTo(null, 'bd'))->toBeTrue();
    });

    it('lets a scope override required, without a second document type', function () {
        // A trade licence can be optional in general and mandatory in one
        // country; two types would show the applicant the same thing twice.
        $type = KycDocumentType::factory()->optional()
            ->scopedTo(country: 'BD', isRequired: true)
            ->create();

        expect($type->isRequiredFor(null, 'BD'))->toBeTrue();
    });

    it('falls back to the type\'s own setting when a scope says nothing', function () {
        $type = KycDocumentType::factory()->optional()->scopedTo(country: 'BD')->create();

        expect($type->isRequiredFor(null, 'BD'))->toBeFalse();
    });

    it('lets a country rule outrank a package rule', function () {
        /*
         * The deliberate part of the priority. A country rule is usually there
         * because the law requires it; letting a commercial package rule
         * override it would make a plan choice able to drop a legal
         * requirement.
         */
        $type = KycDocumentType::factory()
            ->scopedTo(package: 'enterprise', isRequired: false)
            ->scopedTo(country: 'BD', isRequired: true)
            ->create();

        expect($type->isRequiredFor('enterprise', 'BD'))->toBeTrue();
    });

    it('lets the narrowest rule of all outrank both', function () {
        $type = KycDocumentType::factory()
            ->scopedTo(country: 'BD', isRequired: true)
            ->scopedTo(package: 'enterprise', country: 'BD', isRequired: false)
            ->create();

        expect($type->isRequiredFor('enterprise', 'BD'))->toBeFalse()
            ->and($type->isRequiredFor('starter', 'BD'))->toBeTrue();
    });

    it('does not apply a scoped type when no rule matches', function () {
        $type = KycDocumentType::factory()->scopedTo(country: 'BD')->create();

        expect($type->appliesTo('enterprise', 'IN'))->toBeFalse();
    });
});

describe('the round snapshot', function () {
    it('records what the round was opened against', function () {
        KycDocumentType::factory()->create(['name' => 'National ID']);
        KycDocumentType::factory()->scopedTo(country: 'IN')->create(['name' => 'PAN card']);

        $account = scopingTestAccount('BD');
        $submission = app(OpenKycDraft::class)->handle($account);

        expect($submission->requirements()->pluck('name')->all())->toBe(['National ID']);
    });

    it('does not let a later configuration change rewrite an open round', function () {
        /*
         * The failure this guards: an administrator adds a requirement this
         * morning, and an applicant who completed everything they were shown
         * yesterday is suddenly incomplete — while the form still tells them
         * they are done.
         */
        KycDocumentType::factory()->create(['name' => 'National ID']);

        $account = scopingTestAccount();
        $submission = app(OpenKycDraft::class)->handle($account);

        KycDocumentType::factory()->create(['name' => 'Trade licence']);

        expect($submission->refresh()->requirements()->count())->toBe(1)
            ->and(app(ApplicableRequirements::class)->forForm($account, $submission))
            ->toHaveCount(1);
    });

    it('does not let an edited type rewrite a historical round', function () {
        $type = KycDocumentType::factory()->create([
            'name' => 'National ID',
            'max_size_kb' => 5120,
        ]);

        $account = scopingTestAccount();
        $submission = app(OpenKycDraft::class)->handle($account);

        $type->forceFill(['name' => 'Renamed', 'max_size_kb' => 100])->save();

        $requirement = $submission->requirements()->first();

        expect($requirement->name)->toBe('National ID')
            ->and($requirement->max_size_kb)->toBe(5120);
    });

    it('judges completeness against the snapshot, not live configuration', function () {
        KycDocumentType::factory()->optional()->create(['name' => 'National ID']);

        $account = scopingTestAccount();
        $submission = app(OpenKycDraft::class)->handle($account);

        // Made mandatory after the round opened. The applicant was never told.
        KycDocumentType::query()->update(['is_required' => true]);

        expect(app(SubmitKyc::class)->missingRequirements($submission, null, 'BD'))
            ->toBeEmpty();
    });

    it('is captured once and never re-taken', function () {
        // Re-snapshotting on a revisit is exactly the rewrite this prevents.
        KycDocumentType::factory()->create();

        $account = scopingTestAccount();
        $submission = app(OpenKycDraft::class)->handle($account);

        KycDocumentType::factory()->create(['name' => 'Added later']);

        expect(app(CaptureRoundRequirements::class)->handle($submission))->toBe(0)
            ->and($submission->requirements()->count())->toBe(1);
    });

    it('gives a resubmission the configuration as it stands when it opens', function () {
        /*
         * The opposite of the rule above, and deliberately: a new round is a
         * fresh ask. Copying stale rules forward would keep demanding a
         * document that has since been withdrawn.
         */
        KycDocumentType::factory()->create(['name' => 'National ID']);

        $account = scopingTestAccount();
        $first = app(OpenKycDraft::class)->handle($account);

        $first->forceFill(['status' => KycStatus::ResubmissionRequired])->save();

        KycDocumentType::factory()->create(['name' => 'Trade licence']);

        $second = app(OpenKycDraft::class)->handle($account->refresh());

        expect($second->round)->toBe(2)
            ->and($second->requirements()->pluck('name')->all())
            ->toBe(['National ID', 'Trade licence']);
    });

    it('excludes an inactive or archived type from a new round', function (string $state) {
        KycDocumentType::factory()->create(['name' => 'National ID']);
        KycDocumentType::factory()->{$state}()->create(['name' => 'Withdrawn']);

        $submission = app(OpenKycDraft::class)->handle(scopingTestAccount());

        expect($submission->requirements()->pluck('name')->all())->toBe(['National ID']);
    })->with(['inactive', 'archived']);

    it('falls back to live configuration for a round that has no snapshot', function () {
        // Rounds opened before snapshots existed. A form rendering nothing at
        // all would be worse than one built from configuration as it stands.
        KycDocumentType::factory()->create(['name' => 'National ID']);

        $account = scopingTestAccount();

        $legacy = KycSubmission::create([
            'business_account_id' => $account->id,
            'status' => KycStatus::Draft,
            'round' => 1,
        ]);

        expect(app(ApplicableRequirements::class)->forForm($account, $legacy))
            ->toHaveCount(1);
    });

    it('never puts a database id in the form payload', function () {
        // §34.2. The form addresses a requirement by its key.
        KycDocumentType::factory()->key('national_id')->create();

        $account = scopingTestAccount();
        $submission = app(OpenKycDraft::class)->handle($account);

        $payload = app(ApplicableRequirements::class)->forForm($account, $submission);

        expect($payload[0])->not->toHaveKey('id')
            ->and($payload[0]['key'])->toBe('national_id');
    });
});
