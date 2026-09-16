<?php

use App\Domain\Account\Enums\AccountRole;
use App\Domain\Catalog\Models\Product;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Enums\WebsiteTheme;
use App\Domain\Website\Models\Website;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Managing a storefront from the ERP (§16.3, P5-12, P5-14).
 *
 * The list §16.3 gives — information, branding, contact details, theme — and the
 * one thing it forbids: a partner cannot create a product through website
 * management, by any route, whatever they send.
 */
beforeEach(function () {
    Storage::fake('public');

    $this->account = websiteTestAccount();
    $this->website = Website::factory()->forAccount($this->account)->active()->create();
});

describe('what a partner may change', function () {
    it('saves the information, branding and contact details', function () {
        $this->actingAs($this->account->owner)
            ->put(route('websites.settings.update', $this->website->public_id), [
                'name' => 'Nasrin Fashion House',
                'tagline' => 'Everyday cotton, delivered',
                'about' => 'A small shop in Mirpur.',
                'theme' => WebsiteTheme::Modern->value,
                'primary_color' => '#0b0b0b',
                'secondary_color' => '#ff6600',
                'contact_email' => 'hello@nasrin.example',
                'contact_phone' => '01712345678',
                'contact_address' => 'Mirpur 10, Dhaka',
            ])
            ->assertRedirect(route('websites.settings.edit', $this->website->public_id));

        $this->website->refresh();

        expect($this->website->name)->toBe('Nasrin Fashion House')
            ->and($this->website->theme)->toBe(WebsiteTheme::Modern)
            ->and($this->website->primary_color)->toBe('#0b0b0b')
            ->and($this->website->contact_email)->toBe('hello@nasrin.example');
    });

    it('refuses a colour a browser cannot render', function () {
        $this->actingAs($this->account->owner)
            ->from(route('websites.settings.edit', $this->website->public_id))
            ->put(route('websites.settings.update', $this->website->public_id), [
                'name' => 'Nasrin Fashion',
                'theme' => WebsiteTheme::Classic->value,
                'primary_color' => 'orange',
                'secondary_color' => '#ff6600',
            ])
            ->assertSessionHasErrors('primary_color');
    });

    it('stores a logo and gives back an address rather than a path', function () {
        $this->actingAs($this->account->owner)
            ->post(route('websites.settings.image.update', [$this->website->public_id, 'logo']), [
                'image' => UploadedFile::fake()->image('logo.png', 300, 300),
            ])
            ->assertRedirect();

        $this->website->refresh();

        expect($this->website->logo_path)->not->toBeNull();
        Storage::disk('public')->assertExists($this->website->logo_path);

        $page = $this->actingAs($this->account->owner)
            ->get(route('websites.settings.edit', $this->website->public_id));

        $page->assertOk();

        $props = $page->viewData('page')['props'];

        expect($props['website']['logo_url'])->toContain('/storage/websites/')
            ->and($props['website']['logo_url'])->not->toBe($this->website->logo_path);
    });

    it('replaces the file it replaces, and removes the one it removes', function () {
        $this->actingAs($this->account->owner)
            ->post(route('websites.settings.image.update', [$this->website->public_id, 'banner']), [
                'image' => UploadedFile::fake()->image('first.png'),
            ]);

        $first = $this->website->refresh()->banner_path;

        $this->actingAs($this->account->owner)
            ->post(route('websites.settings.image.update', [$this->website->public_id, 'banner']), [
                'image' => UploadedFile::fake()->image('second.png'),
            ]);

        $second = $this->website->refresh()->banner_path;

        expect($second)->not->toBe($first);
        Storage::disk('public')->assertMissing((string) $first);

        $this->actingAs($this->account->owner)
            ->delete(route('websites.settings.image.destroy', [$this->website->public_id, 'banner']));

        expect($this->website->refresh()->banner_path)->toBeNull();
        Storage::disk('public')->assertMissing((string) $second);
    });

    it('refuses anything but a picture a browser renders', function () {
        $this->actingAs($this->account->owner)
            ->from(route('websites.settings.edit', $this->website->public_id))
            ->post(route('websites.settings.image.update', [$this->website->public_id, 'logo']), [
                'image' => UploadedFile::fake()->create('shop.svg', 10, 'image/svg+xml'),
            ])
            ->assertSessionHasErrors('image');

        expect($this->website->refresh()->logo_path)->toBeNull();
    });

    it('is refused for another account\'s website, and for a staff member', function () {
        $others = websiteTestAccount();
        $theirs = Website::factory()->forAccount($others)->active()->create();

        $this->actingAs($this->account->owner)
            ->get(route('websites.settings.edit', $theirs->public_id))
            ->assertNotFound();

        $staff = User::factory()->staffOf($this->account, AccountRole::Staff)->create();

        $this->actingAs($staff)
            ->get(route('websites.settings.edit', $this->website->public_id))
            ->assertForbidden();
    });

    it('will not repaint a closed shop', function () {
        $this->website->forceFill(['status' => WebsiteStatus::Closed, 'closed_at' => now()])->save();

        $this->actingAs($this->account->owner)
            ->from(route('websites.settings.edit', $this->website->public_id))
            ->put(route('websites.settings.update', $this->website->public_id), [
                'name' => 'Reopened',
                'theme' => WebsiteTheme::Classic->value,
                'primary_color' => '#111111',
                'secondary_color' => '#f97316',
            ])
            ->assertSessionHasErrors('website');

        expect($this->website->refresh()->name)->not->toBe('Reopened');
    });
});

describe('products through website management', function () {
    /*
     * §16.3: "Users cannot create new Products through Website Management."
     * The guarantee is structural — there is no such route — so this asserts
     * both halves: no website route creates a product, and the catalogue's own
     * create endpoint refuses a partner outright (§12).
     */
    it('creates none from the selection endpoint, whatever is sent to it', function () {
        $before = Product::query()->count();

        // The only product route under website management *selects* one that
        // already exists. A full product payload posted at it creates nothing:
        // the fields are not even read, and an identifier that names no
        // eligible product is a 404.
        $this->actingAs($this->account->owner)
            ->from(route('websites.products.index', $this->website->public_id))
            ->post(route('websites.products.store', $this->website->public_id), [
                'product' => 'MY-OWN-PRODUCT-PAYLOAD-01',
                'name' => 'My own product',
                'sku' => 'MINE-0001',
                'wholesale_price_minor' => 100,
            ])
            ->assertSessionHasErrors('product');

        $this->actingAs($this->account->owner)
            ->post(route('websites.products.store', $this->website->public_id), [
                'product' => (string) Str::ulid(),
                'name' => 'My own product',
                'sku' => 'MINE-0001',
            ])
            ->assertStatus(404);

        expect(Product::query()->count())->toBe($before)
            ->and(Product::query()->where('name', 'My own product')->exists())->toBeFalse();
    });

    it('refuses a partner the catalogue\'s own create endpoint', function () {
        $this->actingAs($this->account->owner)
            ->post(route('admin.catalog.products.store'), [
                'name' => 'My own product',
                'sku' => 'MINE-0001',
            ])
            ->assertForbidden();

        expect(Product::query()->where('name', 'My own product')->exists())->toBeFalse();
    });

    it('tells the partner where products come from instead, in both languages', function () {
        $this->actingAs($this->account->owner)
            ->get(route('websites.settings.edit', $this->website->public_id))
            ->assertOk();

        foreach (['en', 'bn'] as $locale) {
            expect(__('website.settings.products_body', [], $locale))
                ->not->toBe('website.settings.products_body');
        }
    });
});
