<?php

use App\Domain\Billing\Enums\FeeType;
use App\Domain\Website\Models\Website;
use App\Domain\Website\WebsiteAddresses;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * A local address in the setup form, for trying a storefront on one's own
 * machine. Only when `website.allow_local_addresses` is on (it follows
 * APP_ENV=local); a production site keeps the strict subdomain rule.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    websiteTestFee(FeeType::WebsiteSetup, 500000);
    websiteTestFee(FeeType::WebsiteDomain, 150000);
    websiteTestFee(FeeType::WebsiteHosting, 200000);
});

describe('with local addresses allowed', function () {
    beforeEach(fn () => config(['website.allow_local_addresses' => true]));

    it('takes a URL with a port as the address, with no further checking', function () {
        $account = websiteTestAccount();

        $this->actingAs($account->owner)
            ->post(route('websites.store'), ['name' => 'My Local Shop', 'subdomain' => 'http://127.0.0.1:8001'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $website = Website::query()->firstOrFail();

        expect($website->domain)->toBe('127.0.0.1:8001')
            ->and($website->subdomain)->toStartWith('local-127-0-0-1-8001')
            ->and($website->origin())->toBe('http://127.0.0.1:8001');
    });

    it('takes a bare host and port, and localhost', function () {
        $account = websiteTestAccount(limit: 2);

        foreach (['127.0.0.1:8002', 'localhost:8003'] as $address) {
            $this->actingAs($account->owner)
                ->post(route('websites.store'), ['name' => 'Shop '.$address, 'subdomain' => $address])
                ->assertSessionHasNoErrors();
        }

        expect(Website::query()->orderBy('id')->pluck('domain')->all())->toBe(['127.0.0.1:8002', 'localhost:8003']);
    });

    it('still takes an ordinary subdomain, strictly', function () {
        $account = websiteTestAccount();

        $this->actingAs($account->owner)
            ->from(route('websites.create'))
            ->post(route('websites.store'), ['name' => 'Plain', 'subdomain' => 'a'])
            ->assertSessionHasErrors('subdomain');

        expect(Website::query()->count())->toBe(0);
    });

    it('refuses the same address twice', function () {
        $account = websiteTestAccount(limit: 2);

        $this->actingAs($account->owner)->post(route('websites.store'), ['name' => 'One', 'subdomain' => '127.0.0.1:8001']);

        $this->actingAs($account->owner)
            ->from(route('websites.create'))
            ->post(route('websites.store'), ['name' => 'Two', 'subdomain' => 'http://127.0.0.1:8001'])
            ->assertSessionHasErrors('subdomain');
    });

    it('lets a customer be sent back to the local address after paying, and nowhere else', function () {
        $account = websiteTestAccount();
        $this->actingAs($account->owner)->post(route('websites.store'), ['name' => 'Local', 'subdomain' => '127.0.0.1:8001']);

        $website = Website::query()->firstOrFail();
        $addresses = app(WebsiteAddresses::class);

        expect($addresses->home($website))->toBe('http://127.0.0.1:8001/')
            ->and($addresses->allows($website, 'http://127.0.0.1:8001/payment/return?order=1'))->toBeTrue()
            ->and($addresses->allows($website, 'http://127.0.0.1:8002/payment/return'))->toBeFalse()
            ->and($addresses->allows($website, 'http://127.0.0.1:8001@evil.test/'))->toBeFalse()
            ->and($addresses->allows($website, 'http://evil.test/'))->toBeFalse();
    });
});

describe('with local addresses not allowed (production)', function () {
    it('keeps the strict subdomain rule and refuses a URL', function () {
        config(['website.allow_local_addresses' => false]);
        $account = websiteTestAccount();

        $this->actingAs($account->owner)
            ->from(route('websites.create'))
            ->post(route('websites.store'), ['name' => 'Nope', 'subdomain' => 'http://127.0.0.1:8001'])
            ->assertSessionHasErrors('subdomain');

        expect(Website::query()->count())->toBe(0);
    });

    it('never treats a stored host and port as a place to send a customer', function () {
        config(['website.allow_local_addresses' => false]);
        $website = Website::factory()->create(['domain' => '127.0.0.1:8001']);

        expect(app(WebsiteAddresses::class)->allows($website, 'http://127.0.0.1:8001/'))->toBeFalse()
            ->and($website->origin())->toStartWith('https://');
    });
});
