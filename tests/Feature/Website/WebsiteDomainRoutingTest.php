<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Website\Actions\ManageWebsiteDomainRouting;
use App\Domain\Website\Enums\DomainRoutingStatus;
use App\Domain\Website\Enums\WebsiteServiceStatus;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteDomain;
use App\Integrations\Dns\Contracts\TxtRecordResolver;
use App\Integrations\Dns\Exceptions\DnsLookupFailed;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proving a custom domain before the shared storefront answers on it, and the
 * certificate check that depends on it (D30 §3, §4).
 *
 * Verification is evidence — the DNS here is faked, never assumed — and a name
 * proven by one website can never be proven by another.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Bound before anything resolves the resolver, so no test ever reaches the
    // real DNS. Empty until a test says what it holds.
    app()->instance(TxtRecordResolver::class, new class implements TxtRecordResolver
    {
        /** @var array<string, list<string>>|null */
        public ?array $records = [];

        public function txtRecords(string $name): array
        {
            if ($this->records === null) {
                throw new DnsLookupFailed('down');
            }

            return $this->records[$name] ?? [];
        }
    });

    $this->staff = testPlatformStaff(PlatformRole::SuperAdmin);
    $this->website = Website::factory()->forAccount(websiteTestAccount())->active()->create();
    $this->domain = WebsiteDomain::factory()->create(['website_id' => $this->website->id, 'domain' => 'karim.example']);
    $this->routing = app(ManageWebsiteDomainRouting::class);
});

/**
 * What the DNS answers, for the rest of the test. `null` means the lookup
 * itself fails.
 *
 * @param  array<string, list<string>>|null  $records  TXT strings by name
 */
function domainRoutingDns(?array $records): void
{
    app(TxtRecordResolver::class)->records = $records;
}

/**
 * Issue a token and publish it in the faked DNS, as the domain owner would.
 */
function domainRoutingProve(WebsiteDomain $domain, mixed $staff): WebsiteDomain
{
    $issued = app(ManageWebsiteDomainRouting::class)->issueVerification($domain, $staff);
    domainRoutingDns([$issued->verificationRecordName() => [(string) $issued->verification_token]]);

    expect(app(ManageWebsiteDomainRouting::class)->verify($issued, $staff))->toBeTrue();

    return $issued->refresh();
}

describe('verification', function () {
    it('starts every domain, existing ones included, unverified', function () {
        expect($this->domain->refresh()->routing_status)->toBe(DomainRoutingStatus::Unverified)
            ->and($this->domain->serves())->toBeFalse();
    });

    it('verifies only when the DNS publishes the issued token', function () {
        $issued = $this->routing->issueVerification($this->domain, $this->staff);

        expect($issued->verification_token)->toStartWith('banij-verify-')
            ->and($issued->verificationRecordName())->toBe('_banij-verify.karim.example');

        domainRoutingDns([]);
        expect($this->routing->verify($issued, $this->staff))->toBeFalse()
            ->and($issued->refresh()->routing_status)->toBe(DomainRoutingStatus::Unverified)
            ->and($issued->verification_error)->toContain('_banij-verify.karim.example');

        domainRoutingDns(['_banij-verify.karim.example' => ['somebody-else']]);
        expect($this->routing->verify($issued, $this->staff))->toBeFalse();

        domainRoutingDns(['_banij-verify.karim.example' => [(string) $issued->verification_token]]);
        expect($this->routing->verify($issued, $this->staff))->toBeTrue()
            ->and($issued->refresh()->routing_status)->toBe(DomainRoutingStatus::Verified)
            ->and($issued->verified_at)->not->toBeNull()
            ->and(AuditLog::query()->where('action', 'website.domain_verified')->count())->toBe(1);
    });

    it('treats a DNS lookup that fails as a failure, not a pass', function () {
        $issued = $this->routing->issueVerification($this->domain, $this->staff);
        domainRoutingDns(null);

        expect($this->routing->verify($issued, $this->staff))->toBeFalse()
            ->and($issued->refresh()->routing_status)->toBe(DomainRoutingStatus::Unverified)
            ->and($issued->verification_error)->toBe(__('website.domain_verification.lookup_failed'));
    });

    it('refuses to check a domain that has no token', function () {
        expect(fn () => $this->routing->verify($this->domain, $this->staff))
            ->toThrow(WebsiteRefused::class, __('website.refused.domain_verification_not_issued'));
    });

    it('returns a domain to unverified, off the storefront, when its token is reissued', function () {
        $domain = domainRoutingProve($this->domain, $this->staff);
        $this->routing->activate($domain, $this->staff);

        $reissued = $this->routing->issueVerification($domain->refresh(), $this->staff);

        expect($reissued->routing_status)->toBe(DomainRoutingStatus::Unverified)
            ->and($reissued->verified_at)->toBeNull()
            ->and($reissued->serves())->toBeFalse();
    });
});

describe('activation and suspension', function () {
    it('puts a verified domain on the storefront, and takes it off with a reason', function () {
        $domain = domainRoutingProve($this->domain, $this->staff);

        $active = $this->routing->activate($domain, $this->staff);
        expect($active->routing_status)->toBe(DomainRoutingStatus::Active)
            ->and($active->serves())->toBeTrue();

        $suspended = $this->routing->suspend($active, $this->staff, 'Owner asked to pause it.');
        expect($suspended->routing_status)->toBe(DomainRoutingStatus::Suspended)
            ->and($suspended->serves())->toBeFalse()
            ->and(AuditLog::query()->where('action', 'website.domain_suspended')->value('reason'))->toBe('Owner asked to pause it.');

        expect($this->routing->activate($suspended, $this->staff)->routing_status)->toBe(DomainRoutingStatus::Active);
    });

    it('refuses to activate a domain nobody has proven', function () {
        expect(fn () => $this->routing->activate($this->domain, $this->staff))
            ->toThrow(WebsiteRefused::class, __('website.refused.domain_not_verified'));
    });

    it('refuses to activate a domain whose registration is not running', function () {
        $domain = domainRoutingProve($this->domain, $this->staff);
        $domain->forceFill(['status' => WebsiteServiceStatus::Expired])->save();

        expect(fn () => $this->routing->activate($domain, $this->staff))
            ->toThrow(WebsiteRefused::class, __('website.refused.domain_not_registered'));
    });

    it('refuses to activate a domain of a closed website', function () {
        $domain = domainRoutingProve($this->domain, $this->staff);
        $this->website->forceFill(['status' => WebsiteStatus::Closed])->save();

        expect(fn () => $this->routing->activate($domain->refresh(), $this->staff))
            ->toThrow(WebsiteRefused::class, __('website.refused.closed'));
    });

    it('refuses to suspend a domain that is not on the storefront', function () {
        expect(fn () => $this->routing->suspend($this->domain, $this->staff, 'Not live anyway.'))
            ->toThrow(WebsiteRefused::class, __('website.refused.domain_not_active'));
    });
});

describe('who may hold a name', function () {
    it('never lets a second website prove a name another has proven, even after its registration lapsed', function () {
        domainRoutingProve($this->domain, $this->staff);
        $this->domain->refresh()->forceFill(['status' => WebsiteServiceStatus::Expired])->save();

        $intruder = Website::factory()->forAccount(websiteTestAccount())->active()->create();
        $claim = WebsiteDomain::factory()->create(['website_id' => $intruder->id, 'domain' => 'karim.example']);

        $issued = $this->routing->issueVerification($claim, $this->staff);
        domainRoutingDns([$issued->verificationRecordName() => [(string) $issued->verification_token]]);

        expect(fn () => $this->routing->verify($issued, $this->staff))
            ->toThrow(WebsiteRefused::class, __('website.refused.domain_held_elsewhere'));

        expect($claim->refresh()->routing_status)->toBe(DomainRoutingStatus::Unverified)
            ->and($this->domain->refresh()->routing_status)->toBe(DomainRoutingStatus::Verified);
    });
});

describe('the admin screen', function () {
    it('shows staff the record to publish, and the partner nothing of it', function () {
        $this->routing->issueVerification($this->domain, $this->staff);

        $this->actingAs($this->staff)
            ->get(route('admin.websites.show', $this->website->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('website.domains.0.routing_status', 'unverified')
                ->where('website.domains.0.verification.record_name', '_banij-verify.karim.example'));

        $this->actingAs($this->website->businessAccount->owner)
            ->get(route('websites.show', $this->website->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('website.domains.0.verification', null)
                ->where('website.domains.0.tls_authorized_at', null));
    });

    it('lets staff prove and activate a domain through the screen', function () {
        $this->actingAs($this->staff)
            ->post(route('admin.websites.domains.verification.store', [$this->website->public_id, $this->domain->id]))
            ->assertSessionHasNoErrors();

        $domain = $this->domain->refresh();
        domainRoutingDns([$domain->verificationRecordName() => [(string) $domain->verification_token]]);

        $this->actingAs($this->staff)
            ->post(route('admin.websites.domains.verification.check', [$this->website->public_id, $domain->id]))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->staff)
            ->post(route('admin.websites.domains.activation.store', [$this->website->public_id, $domain->id]))
            ->assertSessionHasNoErrors();

        expect($domain->refresh()->routing_status)->toBe(DomainRoutingStatus::Active);

        $this->actingAs($this->staff)
            ->post(route('admin.websites.domains.suspension.store', [$this->website->public_id, $domain->id]), ['reason' => 'no'])
            ->assertSessionHasErrors('reason');
    });

    it('keeps the controls away from the partner', function () {
        $this->actingAs($this->website->businessAccount->owner)
            ->post(route('admin.websites.domains.verification.store', [$this->website->public_id, $this->domain->id]))
            ->assertForbidden();

        expect($this->domain->refresh()->verification_token)->toBeNull();
    });

    it('answers a domain of another website as not found', function () {
        $other = WebsiteDomain::factory()->create();

        $this->actingAs($this->staff)
            ->post(route('admin.websites.domains.verification.store', [$this->website->public_id, $other->id]))
            ->assertNotFound();

        expect($other->refresh()->verification_token)->toBeNull();
    });
});

/**
 * Caddy asking whether it may issue a certificate for `$domain`.
 *
 * @return TestResponse<Response>
 */
function domainRoutingAsk(string $domain, ?string $token = 'caddy-ask-token'): TestResponse
{
    return test()->get(route('internal.tls.authorize', array_filter(['token' => $token, 'domain' => $domain])));
}

describe('the certificate check', function () {
    beforeEach(function () {
        config(['website.tls.ask_token' => 'caddy-ask-token', 'website.tls.ask_allowed_ips' => []]);
    });

    it('answers nothing without the configured token', function () {
        config(['website.tls.ask_token' => null]);
        domainRoutingAsk($this->website->subdomain.'.'.config('website.storefront_domain'))->assertNotFound();

        config(['website.tls.ask_token' => 'caddy-ask-token']);
        domainRoutingAsk($this->website->subdomain.'.'.config('website.storefront_domain'), 'wrong')->assertNotFound();
        domainRoutingAsk($this->website->subdomain.'.'.config('website.storefront_domain'), null)->assertNotFound();
    });

    it('answers nothing from an address outside the allowed list', function () {
        config(['website.tls.ask_allowed_ips' => ['10.0.0.0/8']]);

        domainRoutingAsk($this->website->subdomain.'.'.config('website.storefront_domain'))->assertNotFound();
    });

    it('allows a website\'s own subdomain, and refuses a closed website\'s', function () {
        domainRoutingAsk($this->website->subdomain.'.'.config('website.storefront_domain'))->assertOk();

        $this->website->forceFill(['status' => WebsiteStatus::Closed])->save();

        domainRoutingAsk($this->website->subdomain.'.'.config('website.storefront_domain'))->assertForbidden();
    });

    it('allows a custom domain only once it is verified, active and registered', function () {
        domainRoutingAsk('karim.example')->assertForbidden();

        $domain = domainRoutingProve($this->domain, $this->staff);
        domainRoutingAsk('karim.example')->assertForbidden();

        $this->routing->activate($domain, $this->staff);
        domainRoutingAsk('karim.example')->assertOk();
        domainRoutingAsk('KARIM.example.')->assertOk();

        expect($domain->refresh()->tls_authorized_at)->not->toBeNull()
            ->and(AuditLog::query()->where('action', 'website.domain_certificate_authorized')->count())->toBe(1);

        // Asked again: authorised again, audited once.
        domainRoutingAsk('karim.example')->assertOk();
        expect(AuditLog::query()->where('action', 'website.domain_certificate_authorized')->count())->toBe(1);

        $domain->refresh()->forceFill(['status' => WebsiteServiceStatus::Expired])->save();
        domainRoutingAsk('karim.example')->assertForbidden();
    });

    it('keeps HTTPS for a suspended website, so it can say it is unavailable (D6)', function () {
        $domain = domainRoutingProve($this->domain, $this->staff);
        $this->routing->activate($domain, $this->staff);
        $this->website->forceFill(['status' => WebsiteStatus::Suspended])->save();

        domainRoutingAsk($this->website->subdomain.'.'.config('website.storefront_domain'))->assertOk();
        domainRoutingAsk('karim.example')->assertOk();
    });

    it('refuses a suspended domain any new authorisation', function () {
        $domain = domainRoutingProve($this->domain, $this->staff);
        $active = $this->routing->activate($domain, $this->staff);
        domainRoutingAsk('karim.example')->assertOk();

        $this->routing->suspend($active, $this->staff, 'Taken off for review.');

        domainRoutingAsk('karim.example')->assertForbidden();
    });

    it('refuses names that belong to nobody', function (string $name) {
        domainRoutingAsk($name)->assertForbidden();
    })->with([
        'an unknown domain' => ['unknown.example'],
        'an address' => ['203.0.113.10'],
        'a nested name under the storefront domain' => ['a.b.feriwala.shop'],
        'a wildcard' => ['*.feriwala.shop'],
        'nothing' => ['-'],
    ]);
});
