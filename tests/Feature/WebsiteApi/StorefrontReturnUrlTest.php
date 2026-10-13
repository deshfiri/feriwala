<?php

use App\Domain\Website\Enums\DomainRoutingStatus;
use App\Domain\Website\Enums\WebsiteServiceStatus;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteDomain;
use App\Domain\Website\WebsiteAddresses;

/**
 * Where a customer may be sent after paying (P2 H8, D1).
 *
 * Only to a host the website holds now — its storefront subdomain, or a domain
 * that is verified and active — over HTTPS on the standard port, with nothing a
 * browser and a parser could read two ways.
 */
beforeEach(function () {
    $this->website = Website::factory()->forAccount(websiteTestAccount())->active()->create([
        'subdomain' => 'nasrin',
        'domain' => 'shop.test',
    ]);

    WebsiteDomain::factory()->serving()->create(['website_id' => $this->website->id, 'domain' => 'shop.test']);
    WebsiteDomain::factory()->serving()->create(['website_id' => $this->website->id, 'domain' => 'alias.test']);
    WebsiteDomain::factory()->verified()->create(['website_id' => $this->website->id, 'domain' => 'pending.test']);
    WebsiteDomain::factory()->serving()->create([
        'website_id' => $this->website->id,
        'domain' => 'lapsed.test',
        'status' => WebsiteServiceStatus::Expired,
    ]);
    WebsiteDomain::factory()->serving()->create([
        'website_id' => $this->website->id,
        'domain' => 'suspended.test',
        'routing_status' => DomainRoutingStatus::Suspended,
    ]);

    $other = Website::factory()->forAccount(websiteTestAccount())->active()->create();
    WebsiteDomain::factory()->serving()->create(['website_id' => $other->id, 'domain' => 'theirs.test']);
});

it('allows the website\'s own hosts, however they are spelled', function (string $url) {
    expect(app(WebsiteAddresses::class)->allows($this->website, $url))->toBeTrue();
})->with([
    'its domain' => 'https://shop.test/thanks',
    'with a query' => 'https://shop.test/thanks?cart=1',
    'in capitals' => 'https://SHOP.TEST/thanks',
    'with a trailing dot' => 'https://shop.test./thanks',
    'on the standard port, stated' => 'https://shop.test:443/thanks',
    'an alias it holds' => 'https://alias.test/',
    'its storefront subdomain' => 'https://nasrin.feriwala.shop/order/done',
]);

it('refuses anywhere else, and anything ambiguous', function (string $url) {
    expect(app(WebsiteAddresses::class)->allows($this->website, $url))->toBeFalse();
})->with([
    'plain http' => 'http://shop.test/thanks',
    'a capitalised scheme' => 'HTTPS://shop.test/thanks',
    'scheme-relative' => '//shop.test/thanks',
    'a script' => 'javascript:alert(1)',
    'credentials' => 'https://user:secret@shop.test/thanks',
    'a username only' => 'https://user@shop.test/thanks',
    'a fragment' => 'https://shop.test/thanks#paid',
    'an empty fragment' => 'https://shop.test/thanks#',
    'another port' => 'https://shop.test:8443/thanks',
    'port 80' => 'https://shop.test:80/thanks',
    'a backslash' => 'https://evil.test\\@shop.test/',
    'whitespace' => 'https://shop.test/ thanks',
    'leading whitespace' => ' https://shop.test/thanks',
    'raw non-ASCII' => 'https://shop.test/ধন্যবাদ',
    'too long' => 'https://shop.test/'.str_repeat('a', 2048),
    'a stranger' => 'https://evil.test/thanks',
    'a lookalike suffix' => 'https://shop.test.evil.test/thanks',
    'a lookalike prefix' => 'https://evilshop.test/thanks',
    'verified, not yet serving' => 'https://pending.test/',
    'a lapsed registration' => 'https://lapsed.test/',
    'a suspended domain' => 'https://suspended.test/',
    'another website\'s domain' => 'https://theirs.test/',
    'a nested storefront subdomain' => 'https://x.nasrin.feriwala.shop/',
    'an address' => 'https://127.0.0.1/',
    'no host' => 'https:///thanks',
    'empty' => '',
]);

it('stops honouring a domain the moment it stops being the website\'s', function () {
    $addresses = app(WebsiteAddresses::class);

    expect($addresses->allows($this->website, 'https://alias.test/'))->toBeTrue();

    WebsiteDomain::query()->where('domain', 'alias.test')->update(['routing_status' => DomainRoutingStatus::Suspended->value]);

    expect($addresses->allows($this->website, 'https://alias.test/'))->toBeFalse();
});

it('sends a customer home to a host the website holds, never to an unverified domain', function () {
    $addresses = app(WebsiteAddresses::class);

    expect($addresses->home($this->website))->toBe('https://shop.test/');

    WebsiteDomain::query()->where('domain', 'shop.test')->update(['routing_status' => DomainRoutingStatus::Verified->value]);

    expect($addresses->home($this->website))->toBe('https://nasrin.feriwala.shop/');

    $this->website->forceFill(['status' => WebsiteStatus::Closed])->save();

    expect($addresses->home($this->website))->toBeNull();
});
