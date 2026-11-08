<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Website\Actions\ManageWebhookEndpoint;
use App\Domain\Website\Enums\DomainRoutingStatus;
use App\Domain\Website\Enums\WebhookEvent;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\WebhookDelivery;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteDomain;
use App\Domain\Website\Models\WebsiteHosting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Correcting what was recorded about a partner's website: its address and term,
 * a registered domain, a hosting term.
 *
 * Until this existed a record could only be added to, never fixed — a misspelt
 * domain meant registering another beside it. Fees and charges stay out: they are
 * financial records.
 */
beforeEach(function () {
    Http::fake();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->account = websiteTestAccount();
    $this->website = Website::factory()->forAccount($this->account)->active()->create([
        'subdomain' => 'nasrin',
        'domain' => 'old.example',
    ]);
    app(ManageWebhookEndpoint::class)->configure($this->website, $this->account->owner, 'https://shop.test/feriwala/webhooks');

    $this->admin = testPlatformStaff(PlatformRole::Admin);
});

/** How many "website updated" hints the website's storefront has been sent. */
function adminRecordsAnnouncements(Website $website): int
{
    return WebhookDelivery::query()
        ->where('website_id', $website->id)
        ->where('event_type', WebhookEvent::WebsiteUpdated->value)
        ->count();
}

/** @return array<string, mixed> */
function adminRecordsDetails(array $overrides = []): array
{
    return [
        'domain' => 'old.example',
        'expires_at' => now()->addYear()->toIso8601String(),
        'grace_ends_at' => now()->addYear()->addDays(14)->toIso8601String(),
        'maintenance_message' => null,
        ...$overrides,
    ];
}

describe('the website’s own details', function () {
    it('corrects the domain and the term, audits it and tells the storefront', function () {
        $this->actingAs($this->admin)
            ->put(route('admin.websites.details.update', $this->website->public_id), adminRecordsDetails([
                'domain' => 'https://www.Nasrin.example/shop',
                'maintenance_message' => 'Back at noon.',
            ]))
            ->assertRedirect(route('admin.websites.show', $this->website->public_id))
            ->assertSessionHasNoErrors();

        $this->website->refresh();

        expect($this->website->domain)->toBe('www.nasrin.example')
            ->and($this->website->maintenance_message)->toBe('Back at noon.')
            ->and($this->website->expires_at?->isFuture())->toBeTrue()
            ->and(adminRecordsAnnouncements($this->website))->toBe(1);

        $audit = AuditLog::query()->where('action', 'website.details_updated')->latest('id')->firstOrFail();

        expect($audit->actor_id)->toBe($this->admin->id)
            ->and($audit->before['domain'])->toBe('old.example')
            ->and($audit->after['domain'])->toBe('www.nasrin.example');
    });

    it('clears the domain when it is left empty', function () {
        $this->actingAs($this->admin)
            ->put(route('admin.websites.details.update', $this->website->public_id), adminRecordsDetails(['domain' => '']))
            ->assertSessionHasNoErrors();

        expect($this->website->refresh()->domain)->toBeNull();
    });

    it('never touches the generated subdomain or the address first asked for, whatever is sent', function () {
        $this->website->forceFill(['requested_address' => 'asked-for.example'])->save();

        $this->actingAs($this->admin)
            ->put(route('admin.websites.details.update', $this->website->public_id), adminRecordsDetails([
                'subdomain' => 'hijacked',
                'requested_address' => 'changed.example',
                'maintenance_message' => 'Back at noon.',
            ]))
            ->assertSessionHasNoErrors();

        $this->website->refresh();

        expect($this->website->maintenance_message)->toBe('Back at noon.')
            ->and($this->website->subdomain)->toBe('nasrin')
            ->and($this->website->requested_address)->toBe('asked-for.example');
    });

    it('leaves no audit row and sends nothing when nothing changed', function () {
        $this->website->forceFill([
            'expires_at' => now()->addYear(),
            'grace_ends_at' => now()->addYear()->addDays(14),
        ])->save();

        $this->actingAs($this->admin)
            ->put(route('admin.websites.details.update', $this->website->public_id), adminRecordsDetails([
                'expires_at' => $this->website->expires_at->toIso8601String(),
                'grace_ends_at' => $this->website->grace_ends_at->toIso8601String(),
            ]))
            ->assertSessionHasNoErrors();

        expect(AuditLog::query()->where('action', 'website.details_updated')->count())->toBe(0)
            ->and(adminRecordsAnnouncements($this->website))->toBe(0);
    });

    it('refuses a domain that is not a real name', function () {
        $this->actingAs($this->admin)->from(route('admin.websites.show', $this->website->public_id))
            ->put(route('admin.websites.details.update', $this->website->public_id), adminRecordsDetails(['domain' => 'not a domain']))
            ->assertSessionHasErrors('domain');

        expect($this->website->refresh()->domain)->toBe('old.example');
    });

    it('refuses an address another website already answers on', function () {
        Website::factory()->forAccount(websiteTestAccount())->active()->create(['domain' => 'held.example']);

        $this->actingAs($this->admin)->from(route('admin.websites.show', $this->website->public_id))
            ->put(route('admin.websites.details.update', $this->website->public_id), adminRecordsDetails(['domain' => 'held.example']))
            ->assertSessionHasErrors(['domain' => __('website.refused.domain_taken')]);

        expect($this->website->refresh()->domain)->toBe('old.example');
    });

    it('refuses a grace period that ends before the website does', function () {
        $this->actingAs($this->admin)->from(route('admin.websites.show', $this->website->public_id))
            ->put(route('admin.websites.details.update', $this->website->public_id), adminRecordsDetails([
                'expires_at' => now()->addYear()->toIso8601String(),
                'grace_ends_at' => now()->addMonths(6)->toIso8601String(),
            ]))
            ->assertSessionHasErrors(['grace_ends_at' => __('website.refused.grace_before_expiry')]);
    });

    it('does not edit a closed website', function () {
        $this->website->forceFill(['status' => WebsiteStatus::Closed, 'closed_at' => now()])->save();

        $this->actingAs($this->admin)->from(route('admin.websites.show', $this->website->public_id))
            ->put(route('admin.websites.details.update', $this->website->public_id), adminRecordsDetails(['domain' => 'reopened.example']))
            ->assertSessionHasErrors('website');

        expect($this->website->refresh()->domain)->toBe('old.example');
    });

    it('refuses a partner and staff who may only look', function () {
        $viewer = User::factory()->staff()->create();
        $viewer->givePermissionTo('website.view');
        $url = route('admin.websites.details.update', $this->website->public_id);

        $this->actingAs($this->account->owner)->put($url, adminRecordsDetails(['domain' => 'hijacked.example']))->assertForbidden();
        $this->actingAs($viewer)->put($url, adminRecordsDetails(['domain' => 'hijacked.example']))->assertForbidden();

        expect($this->website->refresh()->domain)->toBe('old.example');
    });
});

describe('a registered domain', function () {
    beforeEach(function () {
        $this->domain = WebsiteDomain::factory()->serving()->create([
            'website_id' => $this->website->id,
            'domain' => 'old.example',
            'registrar' => 'Namecheap',
            'reminded_at' => now(),
            'reminder_stage' => 2,
        ]);
    });

    $payload = fn (array $overrides = []) => [
        'domain' => 'old.example',
        'registrar' => 'Namecheap',
        'expires_at' => now()->addYears(2)->toIso8601String(),
        'auto_renew' => 1,
        'make_primary' => 0,
        ...$overrides,
    ];

    it('changes the registrar and the term without touching its routing, and re-arms the reminders', function () use ($payload) {
        $this->actingAs($this->admin)
            ->put(route('admin.websites.domains.update', [$this->website->public_id, $this->domain->id]), $payload([
                'registrar' => 'GoDaddy',
            ]))
            ->assertSessionHasNoErrors();

        $this->domain->refresh();

        expect($this->domain->registrar)->toBe('GoDaddy')
            ->and($this->domain->auto_renew)->toBeTrue()
            ->and($this->domain->routing_status)->toBe(DomainRoutingStatus::Active)
            ->and($this->domain->reminded_at)->toBeNull()
            ->and($this->domain->reminder_stage)->toBeNull()
            ->and(adminRecordsAnnouncements($this->website))->toBe(0);
    });

    it('starts a renamed domain’s proof over, takes it off the storefront and tells it so', function () use ($payload) {
        $this->actingAs($this->admin)
            ->put(route('admin.websites.domains.update', [$this->website->public_id, $this->domain->id]), $payload([
                'domain' => 'New.Example',
            ]))
            ->assertSessionHasNoErrors();

        $this->domain->refresh();

        expect($this->domain->domain)->toBe('new.example')
            ->and($this->domain->routing_status)->toBe(DomainRoutingStatus::Unverified)
            ->and($this->domain->verification_token)->toBeNull()
            ->and($this->domain->verified_at)->toBeNull()
            ->and($this->domain->activated_at)->toBeNull()
            ->and($this->domain->serves())->toBeFalse()
            // It was the website's address, so the address follows the rename.
            ->and($this->website->refresh()->domain)->toBe('new.example')
            ->and(adminRecordsAnnouncements($this->website))->toBe(1);

        $audit = AuditLog::query()->where('action', 'website.domain_updated')->latest('id')->firstOrFail();

        expect($audit->before['domain'])->toBe('old.example')
            ->and($audit->after['domain'])->toBe('new.example')
            ->and($audit->note)->toContain('unverified');
    });

    it('refuses a name another live registration holds, and changes nothing', function () use ($payload) {
        WebsiteDomain::factory()->create([
            'website_id' => Website::factory()->forAccount(websiteTestAccount())->active()->create()->id,
            'domain' => 'taken.example',
        ]);

        $this->actingAs($this->admin)->from(route('admin.websites.show', $this->website->public_id))
            ->put(route('admin.websites.domains.update', [$this->website->public_id, $this->domain->id]), $payload([
                'domain' => 'taken.example',
            ]))
            ->assertSessionHasErrors(['domain' => __('website.refused.domain_taken')]);

        $this->domain->refresh();

        expect($this->domain->domain)->toBe('old.example')
            ->and($this->domain->routing_status)->toBe(DomainRoutingStatus::Active)
            ->and($this->website->refresh()->domain)->toBe('old.example');
    });

    it('makes a domain the website’s address when asked', function () use ($payload) {
        $second = WebsiteDomain::factory()->create(['website_id' => $this->website->id, 'domain' => 'second.example']);

        $this->actingAs($this->admin)
            ->put(route('admin.websites.domains.update', [$this->website->public_id, $second->id]), $payload([
                'domain' => 'second.example',
                'make_primary' => 1,
            ]))
            ->assertSessionHasNoErrors();

        expect($this->website->refresh()->domain)->toBe('second.example')
            ->and(adminRecordsAnnouncements($this->website))->toBe(1);
    });

    it('answers 404 for another website’s domain rather than editing it', function () use ($payload) {
        $other = Website::factory()->forAccount(websiteTestAccount())->active()->create();
        $foreign = WebsiteDomain::factory()->create(['website_id' => $other->id, 'domain' => 'foreign.example']);

        $this->actingAs($this->admin)
            ->put(route('admin.websites.domains.update', [$this->website->public_id, $foreign->id]), $payload(['domain' => 'hijacked.example']))
            ->assertNotFound();

        expect($foreign->refresh()->domain)->toBe('foreign.example');
    });

    it('refuses a malformed name and a partner', function () use ($payload) {
        $url = route('admin.websites.domains.update', [$this->website->public_id, $this->domain->id]);

        $this->actingAs($this->admin)->from(route('admin.websites.show', $this->website->public_id))
            ->put($url, $payload(['domain' => 'not a domain']))
            ->assertSessionHasErrors('domain');

        $this->actingAs($this->account->owner)->put($url, $payload(['domain' => 'hijacked.example']))->assertForbidden();

        expect($this->domain->refresh()->domain)->toBe('old.example');
    });
});

describe('a hosting term', function () {
    beforeEach(function () {
        $this->hosting = WebsiteHosting::factory()->create([
            'website_id' => $this->website->id,
            'reminded_at' => now(),
            'reminder_stage' => 1,
        ]);
    });

    it('corrects the plan, the provider and the term, and re-arms the reminders', function () {
        $this->actingAs($this->admin)
            ->put(route('admin.websites.hostings.update', [$this->website->public_id, $this->hosting->id]), [
                'plan' => 'Storefront plus',
                'provider' => 'Hetzner',
                'expires_at' => now()->addYears(2)->toIso8601String(),
                'auto_renew' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->hosting->refresh();

        expect($this->hosting->plan)->toBe('Storefront plus')
            ->and($this->hosting->provider)->toBe('Hetzner')
            ->and($this->hosting->auto_renew)->toBeTrue()
            ->and($this->hosting->reminded_at)->toBeNull()
            ->and($this->hosting->reminder_stage)->toBeNull()
            ->and(AuditLog::query()->where('action', 'website.hosting_updated')->count())->toBe(1);
    });

    it('answers 404 for another website’s hosting', function () {
        $other = Website::factory()->forAccount(websiteTestAccount())->active()->create();
        $foreign = WebsiteHosting::factory()->create(['website_id' => $other->id]);

        $this->actingAs($this->admin)
            ->put(route('admin.websites.hostings.update', [$this->website->public_id, $foreign->id]), [
                'plan' => 'Hijacked',
                'expires_at' => now()->addYear()->toIso8601String(),
                'auto_renew' => 0,
            ])
            ->assertNotFound();

        expect($foreign->refresh()->plan)->toBe('Storefront standard');
    });

    it('refuses staff who may only look', function () {
        $viewer = User::factory()->staff()->create();
        $viewer->givePermissionTo('website.view');

        $this->actingAs($viewer)
            ->put(route('admin.websites.hostings.update', [$this->website->public_id, $this->hosting->id]), [
                'plan' => 'Hijacked',
                'expires_at' => now()->addYear()->toIso8601String(),
                'auto_renew' => 0,
            ])
            ->assertForbidden();

        expect($this->hosting->refresh()->plan)->toBe('Storefront standard');
    });
});
