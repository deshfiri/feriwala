<?php

use App\Domain\Website\Actions\MoveWebsiteStatus;
use App\Domain\Website\Enums\WebsiteChargeStatus;
use App\Domain\Website\Enums\WebsiteChargeType;
use App\Domain\Website\Enums\WebsiteConnectionHealth;
use App\Domain\Website\Enums\WebsiteServiceStatus;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Enums\WebsiteStatusChangeSource;
use App\Domain\Website\Enums\WebsiteStatusReason;
use App\Domain\Website\Enums\WebsiteTheme;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCharge;
use App\Domain\Website\Models\WebsiteDomain;
use App\Domain\Website\Queries\WebsiteOverview;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\QueryException;

/**
 * The status map, and the database holding the column to it (§16.4, P5-9).
 *
 * The CHECK constraints are written out as literals in the migrations, because
 * a constraint is something the database keeps and a migration that generated
 * it from application code would change what an old migration created. These
 * tests are the other half of that bargain: they fail the day an enum gains a
 * case the column will not accept.
 */
describe('the history, in the reader\'s language', function () {
    it('tells a platform move in the language being read, and a person\'s as they wrote it', function () {
        $website = Website::factory()->create();
        $staff = User::factory()->create();
        $move = app(MoveWebsiteStatus::class);

        // Written while the platform ran in English.
        app()->setLocale('en');
        $move->handle($website, WebsiteStatus::Development, WebsiteStatusChangeSource::Billing, new StatusChange(
            reason: WebsiteStatusReason::ChargesSettled->value,
            publicNote: WebsiteStatusReason::ChargesSettled->note(),
        ));
        $move->handle($website->refresh(), WebsiteStatus::Active, WebsiteStatusChangeSource::Staff, new StatusChange(
            actorId: $staff->id,
            reason: 'Storefront built and checked for launch.',
            publicNote: 'Your shop is open.',
        ));

        app()->setLocale('bn');
        $overview = app(WebsiteOverview::class);
        $partner = collect($overview->detail($website->refresh())['history'])->keyBy('new_status');
        $platform = collect($overview->detail($website, forStaff: true)['history'])->keyBy('new_status');

        expect($partner['development']['note'])->toBe(__('website.notes.charges_settled'))
            ->and($partner['development']['note'])->not->toBe('Your charges are paid and the build has started.')
            ->and($partner['development']['reason'])->toBeNull()
            ->and($platform['development']['reason'])->toBe(__('website.reasons.charges_settled'))
            ->and($platform['active']['reason'])->toBe('Storefront built and checked for launch.')
            ->and($partner['active']['note'])->toBe('Your shop is open.');
    });

    it('has every platform reason in both languages', function (string $locale) {
        app()->setLocale($locale);

        foreach (WebsiteStatusReason::cases() as $reason) {
            expect($reason->label())->not->toStartWith('website.')
                ->and($reason->note())->not->toStartWith('website.');
        }
    })->with(['en', 'bn']);
});

describe('every status the enum names', function () {
    it('is a value the column accepts', function () {
        $website = Website::factory()->create();

        foreach (WebsiteStatus::cases() as $status) {
            $website->forceFill([
                'status' => $status,
                // A suspension must carry its reason, by constraint.
                'suspension_reason' => 'Test',
                'suspended_at' => now(),
            ])->save();

            expect($website->refresh()->status)->toBe($status);
        }
    });

    it('is a value the history accepts, from every source', function () {
        $website = Website::factory()->create();

        foreach (WebsiteStatusChangeSource::cases() as $source) {
            foreach (WebsiteStatus::cases() as $status) {
                // The history refuses a change that changes nothing, so each
                // row is written from a different previous status.
                $previous = $status === WebsiteStatus::Closed
                    ? WebsiteStatus::Active
                    : WebsiteStatus::Closed;

                $website->recordStatusChange(
                    $previous,
                    $status,
                    new StatusChange(reason: 'drift check'),
                    ['source' => $source->value],
                );
            }
        }

        expect($website->statusHistory()->count())
            ->toBe(count(WebsiteStatus::cases()) * count(WebsiteStatusChangeSource::cases()));
    });

    it('refuses a status the enum does not name', function () {
        $website = Website::factory()->create();

        expect(fn () => DB::table('websites')
            ->where('id', $website->id)
            ->update(['status' => 'half_built']))
            ->toThrow(QueryException::class);
    });
});

describe('the themes, health and service values', function () {
    it('are the ones the columns accept', function () {
        $website = Website::factory()->create();

        foreach (WebsiteTheme::cases() as $theme) {
            $website->forceFill(['theme' => $theme])->save();
            expect($website->refresh()->theme)->toBe($theme);
        }

        foreach (WebsiteConnectionHealth::cases() as $health) {
            $website->forceFill(['connection_health' => $health])->save();
            expect($website->refresh()->connection_health)->toBe($health);
        }

        foreach (WebsiteServiceStatus::cases() as $status) {
            $domain = WebsiteDomain::factory()->create([
                'website_id' => $website->id,
                'status' => $status,
                // Only an active registration has to carry its term.
                'registered_at' => now()->subMonth(),
                'expires_at' => now()->addYear(),
            ]);

            expect($domain->refresh()->status)->toBe($status);
        }

        foreach (WebsiteChargeType::cases() as $type) {
            foreach (WebsiteChargeStatus::cases() as $status) {
                $charge = WebsiteCharge::factory()->create([
                    'website_id' => $website->id,
                    'business_account_id' => $website->business_account_id,
                    'type' => $type,
                    // A paid charge must point at the transaction that settled
                    // it, by constraint, so only the open states are written.
                    'status' => $status === WebsiteChargeStatus::Paid
                        ? WebsiteChargeStatus::Due
                        : $status,
                ]);

                expect($charge->refresh()->type)->toBe($type);
            }
        }
    });

    it('refuses a colour that is not a hex value', function () {
        $website = Website::factory()->create();

        expect(fn () => DB::table('websites')
            ->where('id', $website->id)
            ->update(['primary_color' => 'orange']))
            ->toThrow(QueryException::class);
    });

    it('refuses a charge marked paid with nothing that paid it', function () {
        $website = Website::factory()->create();
        $charge = WebsiteCharge::factory()->create([
            'website_id' => $website->id,
            'business_account_id' => $website->business_account_id,
        ]);

        expect(fn () => DB::table('website_charges')
            ->where('id', $charge->id)
            ->update(['status' => WebsiteChargeStatus::Paid->value]))
            ->toThrow(QueryException::class);
    });
});

describe('the map itself', function () {
    it('ends at closed and nowhere else', function () {
        foreach (WebsiteStatus::cases() as $status) {
            expect($status->isTerminal())->toBe($status === WebsiteStatus::Closed)
                ->and($status->transitionsTo() === [])->toBe($status === WebsiteStatus::Closed);
        }
    });

    it('never offers a move to itself', function () {
        foreach (WebsiteStatus::cases() as $status) {
            expect($status->transitionsTo())->not->toContain($status);
        }
    });

    it('keeps a live storefront live through a renewal or a low balance', function () {
        expect(WebsiteStatus::Active->isLive())->toBeTrue()
            ->and(WebsiteStatus::LowWalletBalance->isLive())->toBeTrue()
            ->and(WebsiteStatus::GracePeriod->isLive())->toBeTrue()
            ->and(WebsiteStatus::DomainRenewalPending->isLive())->toBeTrue()
            ->and(WebsiteStatus::HostingRenewalPending->isLive())->toBeTrue()
            // And stops it where §16.4 says the shop is not being served.
            ->and(WebsiteStatus::Suspended->isLive())->toBeFalse()
            ->and(WebsiteStatus::TemporarilyDisabled->isLive())->toBeFalse()
            ->and(WebsiteStatus::PackageExpired->isLive())->toBeFalse()
            ->and(WebsiteStatus::Maintenance->isLive())->toBeFalse()
            ->and(WebsiteStatus::Closed->isLive())->toBeFalse();
    });
});
