<?php

use App\Domain\Website\Enums\CredentialScope;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteDomain;
use App\Integrations\Payment\PaymentGatewayManager;
use Illuminate\Support\Facades\DB;

/**
 * What the shared storefront reads about the website it is serving, and the
 * checks that keep one deployment's websites apart (D30, contract §3.5, §4.6, §5.4).
 */
beforeEach(function () {
    $this->account = websiteTestAccount();
    $this->website = Website::factory()->forAccount($this->account)->active()->create([
        'name' => 'Karim Fashion',
        'tagline' => 'Cotton for every season',
        'contact_email' => 'hello@karim.example',
    ]);

    [$this->credential, $this->secret] = storefrontCredential($this->website);
});

describe('GET /site', function () {
    it('describes the credential\'s own website for presenting it', function () {
        $domain = WebsiteDomain::factory()->serving()->create([
            'website_id' => $this->website->id,
            'domain' => 'karim.example',
        ]);
        $this->website->forceFill(['domain' => $domain->domain])->save();

        storefrontCall($this->credential, $this->secret, 'site')
            ->assertOk()
            ->assertJsonPath('data.id', $this->website->public_id)
            ->assertJsonPath('data.name', 'Karim Fashion')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.hosts.primary', 'karim.example')
            ->assertJsonPath('data.hosts.subdomain', $this->website->subdomain.'.'.config('website.storefront_domain'))
            ->assertJsonPath('data.branding.tagline', 'Cotton for every season')
            ->assertJsonPath('data.contact.email', 'hello@karim.example')
            ->assertJsonPath('data.currency', 'BDT')
            ->assertJsonPath('data.locale.available', ['en', 'bn'])
            ->assertJsonPath('data.checkout.guest_checkout', true);
    });

    it('lists the gateways the ERP has switched on, so checkout can offer them', function () {
        $this->mock(PaymentGatewayManager::class)
            ->shouldReceive('availableFor')->andReturn(['eps', 'bkash']);

        $checkout = storefrontCall($this->credential, $this->secret, 'site')
            ->assertOk()
            ->json('data.checkout');

        expect($checkout['payment_methods'])->toContain('online')
            ->and(array_column($checkout['gateways'], 'name'))->toBe(['eps', 'bkash'])
            ->and($checkout['gateways'][0])->toHaveKeys(['name', 'label']);
    });

    it('lists no gateway when none is switched on', function () {
        $this->mock(PaymentGatewayManager::class)
            ->shouldReceive('availableFor')->andReturn([]);

        $checkout = storefrontCall($this->credential, $this->secret, 'site')->assertOk()->json('data.checkout');

        expect($checkout['gateways'])->toBe([])
            ->and($checkout['payment_methods'])->not->toContain('online');
    });

    it('carries no secret and nothing about the owner\'s account', function () {
        $response = storefrontCall($this->credential, $this->secret, 'site')->assertOk();
        $body = $response->getContent();

        expect($body)
            ->not->toContain($this->secret)
            ->not->toContain($this->credential->key_id)
            ->not->toContain($this->account->public_id)
            ->not->toContain('business_account')
            ->not->toContain('wallet')
            ->not->toContain('package')
            ->and(array_keys($response->json('data')))->toBe([
                'id', 'name', 'status', 'accepting_orders', 'maintenance_message', 'hosts',
                'branding', 'contact', 'locale', 'currency', 'checkout',
            ]);
    });

    it('never shows a domain that is not verified and active', function () {
        WebsiteDomain::factory()->verified()->create(['website_id' => $this->website->id, 'domain' => 'proven.example']);
        WebsiteDomain::factory()->create(['website_id' => $this->website->id, 'domain' => 'unproven.example']);

        $hosts = storefrontCall($this->credential, $this->secret, 'site')->json('data.hosts');

        expect($hosts['primary'])->toBe($hosts['subdomain'])
            ->and($hosts['aliases'])->toBe([]);
    });

    it('says a suspended website is unavailable without saying why, and takes no orders', function () {
        $this->website->forceFill(['status' => WebsiteStatus::Suspended, 'suspension_reason' => 'Unpaid charges'])->save();

        // A suspended website cannot use the API at all.
        storefrontCall($this->credential, $this->secret, 'site')->assertForbidden();

        $this->website->forceFill(['status' => WebsiteStatus::Maintenance, 'maintenance_message' => 'Back at noon'])->save();

        storefrontCall($this->credential, $this->secret, 'site')
            ->assertOk()
            ->assertJsonPath('data.status', 'maintenance')
            ->assertJsonPath('data.accepting_orders', false)
            ->assertJsonPath('data.maintenance_message', 'Back at noon');
    });

    it('takes no order and no checkout for a suspended website (D6)', function () {
        [$credential, $secret] = storefrontCredential($this->website, [CredentialScope::OrdersWrite]);
        $this->website->forceFill(['status' => WebsiteStatus::Suspended])->save();

        $body = (string) json_encode(['storefront_order_reference' => 'SF-1']);

        storefrontCall($credential, $secret, 'orders', method: 'POST', body: $body, overrides: [
            'headers' => ['Content-Type' => 'application/json', 'Idempotency-Key' => 'checkout-1'],
        ])->assertForbidden()->assertJsonPath('error.code', 'website_unavailable');

        expect(DB::table('orders')->where('website_id', $this->website->id)->count())->toBe(0);
    });

    it('needs the catalogue scope', function () {
        [$credential, $secret] = storefrontCredential($this->website, [CredentialScope::InventoryRead]);

        storefrontCall($credential, $secret, 'site')->assertForbidden();
    });
});

describe('a stated storefront host', function () {
    it('is accepted when it is this website\'s own subdomain or active domain', function () {
        WebsiteDomain::factory()->serving()->create(['website_id' => $this->website->id, 'domain' => 'karim.example']);
        $subdomain = $this->website->subdomain.'.'.config('website.storefront_domain');

        foreach ([$subdomain, 'KARIM.example.', 'karim.example:443'] as $host) {
            storefrontCall($this->credential, $this->secret, 'site', overrides: [
                'headers' => ['X-Feriwala-Storefront-Host' => $host],
            ])->assertOk();
        }
    });

    it('is refused when it belongs to another website, so a request cannot change shops', function () {
        $other = Website::factory()->forAccount(websiteTestAccount())->active()->create();
        WebsiteDomain::factory()->serving()->create(['website_id' => $other->id, 'domain' => 'other.example']);

        foreach (['other.example', $other->subdomain.'.'.config('website.storefront_domain')] as $host) {
            storefrontCall($this->credential, $this->secret, 'site', overrides: [
                'headers' => ['X-Feriwala-Storefront-Host' => $host],
            ])->assertForbidden()->assertJsonPath('error.code', 'host_mismatch');
        }
    });

    it('is refused when it is unknown, unverified or not a host name at all', function (string $host) {
        WebsiteDomain::factory()->create(['website_id' => $this->website->id, 'domain' => 'unproven.example']);

        storefrontCall($this->credential, $this->secret, 'site', overrides: [
            'headers' => ['X-Feriwala-Storefront-Host' => $host],
        ])->assertForbidden()->assertJsonPath('error.code', 'host_mismatch');
    })->with([
        'unknown' => ['nobody.example'],
        'unverified' => ['unproven.example'],
        'an address' => ['127.0.0.1'],
        'nested under the storefront domain' => ['a.b.feriwala.shop'],
    ]);

    it('changes nothing when it is not sent', function () {
        storefrontCall($this->credential, $this->secret, 'connection')->assertOk();
    });
});

describe('per-website rate limits', function () {
    it('counts every credential of a website against one budget', function () {
        config(['website.api.rate_limits.read' => 2]);
        [$second, $secondSecret] = storefrontCredential($this->website);

        storefrontCall($this->credential, $this->secret, 'site')->assertOk();
        storefrontCall($second, $secondSecret, 'site')
            ->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '0');

        // A fresh credential has budget of its own, but the website does not.
        storefrontCall($second, $secondSecret, 'site')->assertStatus(429);
        storefrontCall($this->credential, $this->secret, 'site')->assertStatus(429);
    });

    it('never lets one website spend another\'s budget', function () {
        config(['website.api.rate_limits.read' => 1]);
        $other = Website::factory()->forAccount(websiteTestAccount())->active()->create();
        [$otherCredential, $otherSecret] = storefrontCredential($other);

        storefrontCall($this->credential, $this->secret, 'site')->assertOk();

        storefrontCall($otherCredential, $otherSecret, 'site')->assertOk();
    });
});
