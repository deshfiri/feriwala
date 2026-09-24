<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Catalog\Actions\SetSalesChannel;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Dropshipping and wholesale flags (P3-10, §11.1, §11.2).
 *
 * Both start off. Each is switched separately, through §11.2's own channel
 * statuses, with the publish permission to switch on and the unpublish
 * permission to switch off — at the action as well as the door — and each move
 * is on the append-only history under its own axis.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::ProductManager);

    $this->product = Product::create([
        'name' => 'Rice cooker',
        'sku' => 'FW-RC',
        'category_id' => Category::create(['name' => 'Kitchen'])->id,
        'wholesale_price' => Money::fromDecimal('2500.00', Currency::BDT),
    ]);
});

function catalogChannelUrl(Product $product, string $channel): string
{
    return route('admin.catalog.products.channels.update', [$product->public_id, $channel]);
}

describe('both channels start off', function () {
    it('holds a new product disabled for dropshipping and for wholesale, in memory and in the table', function () {
        expect($this->product->sellsThrough(SalesChannel::Dropshipping))->toBeFalse()
            ->and($this->product->sellsThrough(SalesChannel::Wholesale))->toBeFalse()
            ->and($this->product->refresh()->wholesale_status)->toBe(ProductStatus::WholesaleDisabled);
    });

    it('admits only its own two statuses in each column', function () {
        expect(fn () => DB::table('products')->where('id', $this->product->id)->update(['wholesale_status' => 'dropshipping_enabled']))
            ->toThrow(QueryException::class, 'products_wholesale_status_known');
    });
});

describe('switching a channel', function () {
    it('switches one channel without touching the other, and records it under its own axis', function () {
        $this->actingAs($this->manager)
            ->patch(catalogChannelUrl($this->product, 'wholesale'), ['enabled' => true])
            ->assertSessionHasNoErrors();

        $this->product->refresh();

        expect($this->product->wholesale_status)->toBe(ProductStatus::WholesaleEnabled)
            ->and($this->product->dropshipping_status)->toBe(ProductStatus::DropshippingDisabled)
            ->and($this->product->statusHistory()->first()?->axis)->toBe('wholesale')
            ->and($this->product->status)->toBe(ProductStatus::Draft);

        $this->actingAs($this->manager)
            ->patch(catalogChannelUrl($this->product, 'wholesale'), ['enabled' => false])
            ->assertSessionHasNoErrors();

        expect($this->product->refresh()->wholesale_status)->toBe(ProductStatus::WholesaleDisabled);
    });

    it('refuses to switch on a channel that is already on', function () {
        app(SetSalesChannel::class)->handle($this->manager, $this->product, SalesChannel::Dropshipping, true);

        $this->actingAs($this->manager)
            ->patch(catalogChannelUrl($this->product, 'dropshipping'), ['enabled' => true])
            ->assertSessionHasErrors('channel');
    });

    it('does not know a channel the specification does not name', function () {
        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.update', $this->product->public_id).'/channels/retail', ['enabled' => true])
            ->assertNotFound();
    });
});

describe('who may switch a channel (§12)', function () {
    it('refuses a business account holder and staff who may only read', function () {
        $this->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
            ->patch(catalogChannelUrl($this->product, 'wholesale'), ['enabled' => true])
            ->assertForbidden();

        $this->actingAs(testPlatformStaff(PlatformRole::InventoryManager))
            ->patch(catalogChannelUrl($this->product, 'wholesale'), ['enabled' => true])
            ->assertForbidden();

        expect($this->product->refresh()->sellsThrough(SalesChannel::Wholesale))->toBeFalse();
    });

    it('refuses to let somebody without publish rights switch a channel on, at the door and in the action', function () {
        $editor = testPlatformStaff(PlatformRole::Admin);

        $this->actingAs($editor)
            ->patch(catalogChannelUrl($this->product, 'wholesale'), ['enabled' => true])
            ->assertForbidden();

        expect(fn () => app(SetSalesChannel::class)->handle($editor, $this->product, SalesChannel::Wholesale, true))
            ->toThrow(AuthorizationException::class);
    });

    it('shows each channel and whether the viewer may switch it', function () {
        $this->actingAs($this->manager)
            ->get(route('admin.catalog.products.edit', $this->product->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.channels.0.channel', 'dropshipping')
                ->where('product.channels.0.enabled', false)
                ->where('product.channels.1.status', 'wholesale_disabled')
                ->where('can.enable_channels', true)
                ->where('can.disable_channels', true),
            );

        $this->actingAs(testPlatformStaff(PlatformRole::Admin))
            ->get(route('admin.catalog.products.edit', $this->product->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('can.enable_channels', false));
    });
});
