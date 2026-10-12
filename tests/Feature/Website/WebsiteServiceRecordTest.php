<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteDomain;
use App\Domain\Website\Models\WebsiteHosting;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * From a partner's request to the domain and hosting they can see (§16.2, D9, P5-11).
 *
 * The partner asks; the request is in the platform's list; staff record the
 * domain and the hosting against that very website, by term length; the
 * partner's My Websites then shows what was recorded and when it starts and
 * ends — and never which registrar or host the platform uses.
 */
beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(now()->setDate(2026, 10, 12)->setTime(10, 0));

    websiteTestFee(FeeType::WebsiteSetup, 500000);
    websiteTestFee(FeeType::WebsiteDomain, 150000);
    websiteTestFee(FeeType::WebsiteHosting, 200000);

    $this->account = websiteTestAccount();
    $this->staff = testPlatformStaff(PlatformRole::SuperAdmin);
});

/**
 * The partner's own request, made through My Websites.
 */
function websiteServiceRecordRequest(object $test): Website
{
    $test->actingAs($test->account->owner)
        ->post(route('websites.store'), ['name' => 'Karim Fashion', 'subdomain' => 'karim-fashion'])
        ->assertSessionHasNoErrors();

    return Website::query()->where('business_account_id', $test->account->id)->sole();
}

it('shows the domain and hosting staff recorded on the partner\'s request, with their dates', function () {
    $website = websiteServiceRecordRequest($this);

    // The request is in the platform's list, against the partner's account.
    $this->actingAs($this->staff)
        ->get(route('admin.websites.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('websites.data.0.id', $website->public_id)
            ->where('websites.data.0.account.name', $this->account->name)
            ->where('websites.data.0.status', 'setup_pending'));

    $this->actingAs($this->staff)
        ->post(route('admin.websites.domains.store', $website->public_id), [
            'domain' => 'karim.com', 'registrar' => 'Namecheap', 'term_months' => 12, 'make_primary' => true,
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->staff)
        ->post(route('admin.websites.hostings.store', $website->public_id), [
            'plan' => 'Standard', 'provider' => 'Hostinger', 'term_months' => 6,
        ])
        ->assertSessionHasNoErrors();

    // Recorded against the request itself, not a copy of it.
    expect(WebsiteDomain::query()->sole()->website_id)->toBe($website->id)
        ->and(WebsiteHosting::query()->sole()->website_id)->toBe($website->id)
        ->and(Website::query()->count())->toBe(1);

    $response = $this->actingAs($this->account->owner)->get(route('websites.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('websites/index')
        ->has('websites', 1)
        ->where('websites.0.id', $website->public_id)
        ->where('websites.0.host', 'karim.com')
        ->where('websites.0.status', 'setup_pending')
        ->where('websites.0.domain_record.domain', 'karim.com')
        ->where('websites.0.domain_record.registered_at', '2026-10-12T10:00:00+06:00')
        ->where('websites.0.domain_record.expires_at', '2027-10-12T10:00:00+06:00')
        ->where('websites.0.hosting_record.plan', 'Standard')
        ->where('websites.0.hosting_record.started_at', '2026-10-12T10:00:00+06:00')
        ->where('websites.0.hosting_record.expires_at', '2027-04-12T10:00:00+06:00')
        ->missing('websites.0.domain_record.registrar')
        ->missing('websites.0.hosting_record.provider'));

    // The platform's suppliers never reach the partner's pages.
    expect($response->getContent())->not->toContain('Namecheap')->not->toContain('Hostinger');

    $this->actingAs($this->account->owner)
        ->get(route('websites.show', $website->public_id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('website.domains.0.registered_at', '2026-10-12T10:00:00+06:00')
            ->where('website.hostings.0.started_at', '2026-10-12T10:00:00+06:00')
            ->where('website.domains.0.registrar', null)
            ->where('website.hostings.0.provider', null));
});

it('says a request has no domain or hosting yet rather than inventing any', function () {
    $website = websiteServiceRecordRequest($this);

    $this->actingAs($this->account->owner)
        ->get(route('websites.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('websites.0.id', $website->public_id)
            ->where('websites.0.domain_record', null)
            ->where('websites.0.hosting_record', null));
});

it('names the hosting plan on the package website card', function () {
    $website = websiteServiceRecordRequest($this);

    $this->actingAs($this->staff)->post(route('admin.websites.hostings.store', $website->public_id), [
        'plan' => 'Standard', 'provider' => 'Hostinger', 'term_months' => 12,
    ]);

    $this->actingAs($this->account->owner)
        ->get(route('websites.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('allocations', 1)
            ->where('allocations.0.website.id', $website->public_id)
            ->where('allocations.0.hosting.provider.plan', 'Standard')
            ->where('allocations.0.hosting.provider.started_at', '2026-10-12T10:00:00+06:00')
            ->missing('allocations.0.hosting.provider.provider'));
});

it('shows a partner only their own websites\' records', function () {
    $mine = websiteServiceRecordRequest($this);
    $this->actingAs($this->staff)->post(route('admin.websites.domains.store', $mine->public_id), [
        'domain' => 'karim.com', 'term_months' => 12,
    ]);

    $other = websiteTestAccount();

    $this->actingAs($other->owner)
        ->get(route('websites.index'))
        ->assertInertia(fn (Assert $page) => $page->has('websites', 0));

    $this->actingAs($other->owner)->get(route('websites.show', $mine->public_id))->assertNotFound();
});

it('lets only platform staff record a domain or hosting', function () {
    $website = websiteServiceRecordRequest($this);

    $this->actingAs($this->account->owner)
        ->post(route('admin.websites.domains.store', $website->public_id), ['domain' => 'karim.com', 'term_months' => 12])
        ->assertForbidden();

    $this->actingAs($this->account->owner)
        ->post(route('admin.websites.hostings.store', $website->public_id), ['plan' => 'Standard', 'term_months' => 12])
        ->assertForbidden();

    expect(WebsiteDomain::query()->count())->toBe(0)
        ->and(WebsiteHosting::query()->count())->toBe(0);
});
