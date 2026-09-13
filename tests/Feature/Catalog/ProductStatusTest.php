<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Catalog\Actions\ManageProducts;
use App\Domain\Catalog\Actions\TransitionProduct;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductStatusChange;
use App\Domain\Catalog\Models\ProductVariant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The product lifecycle (P3-8, §11.2, §12).
 *
 * All eleven statuses exist and each moves only within its own axis. Writing a
 * product and putting it in front of partners are different permissions, and
 * the action checks that as well as the controller. A product is not activated
 * while nobody could place or price it, and every move is on an append-only
 * record.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::ProductManager);

    $this->product = Product::create([
        'name' => 'Rice cooker',
        'sku' => 'FW-RC',
        'category_id' => Category::create(['name' => 'Kitchen'])->id,
        'wholesale_price_minor' => 250000,
    ]);
});

function catalogStatusMove(Product $product, string $status, ?string $reason = null): array
{
    return [route('admin.catalog.products.status.update', $product->public_id), ['status' => $status, 'reason' => $reason]];
}

describe('the eleven statuses of §11.2', function () {
    it('declares every one, each on exactly one axis', function () {
        expect(array_map(fn (ProductStatus $status) => $status->value, ProductStatus::cases()))->toBe([
            'draft', 'pending_review', 'active', 'inactive', 'out_of_stock', 'discontinued', 'archived',
            'dropshipping_enabled', 'dropshipping_disabled', 'wholesale_enabled', 'wholesale_disabled',
        ]);

        expect(ProductStatus::lifecycle())->toHaveCount(7);
    });

    it('never moves a status onto another axis', function () {
        foreach (ProductStatus::cases() as $status) {
            foreach ($status->transitionsTo() as $next) {
                expect($next->axis())->toBe($status->axis());
            }
        }
    });

    it('gives every status words and a tone, never colour alone', function () {
        foreach (ProductStatus::cases() as $status) {
            expect($status->label())->not->toBe('')
                ->and($status->tone())->toBeIn(['success', 'warning', 'danger', 'info', 'neutral'])
                ->and(__('catalog.products.status.'.$status->value))->not->toStartWith('catalog.');
        }
    });

    it('holds only lifecycle statuses in the product column', function () {
        expect(fn () => DB::table('products')->where('id', $this->product->id)->update(['status' => 'wholesale_enabled']))
            ->toThrow(QueryException::class, 'products_status_is_lifecycle');
    });
});

describe('moving a product', function () {
    it('goes from draft through review to active, stamping when it first went live', function () {
        [$url, $payload] = catalogStatusMove($this->product, 'pending_review');
        $this->actingAs($this->manager)->patch($url, $payload)->assertSessionHasNoErrors();

        [$url, $payload] = catalogStatusMove($this->product, 'active');
        $this->actingAs($this->manager)->patch($url, $payload)->assertSessionHasNoErrors();

        $this->product->refresh();
        $firstLive = $this->product->published_at;

        expect($this->product->status)->toBe(ProductStatus::Active)
            ->and($firstLive)->not->toBeNull();

        // Paused and resumed: still first went live when it first went live.
        $this->travel(3)->days();
        app(TransitionProduct::class)->handle($this->manager, $this->product, ProductStatus::Inactive);
        app(TransitionProduct::class)->handle($this->manager, $this->product->refresh(), ProductStatus::Active);

        expect($this->product->refresh()->published_at?->toIso8601String())->toBe($firstLive?->toIso8601String())
            ->and($this->product->statusHistory()->pluck('to_status')->map->value->all())
            ->toBe(['active', 'inactive', 'active', 'pending_review']);
    });

    it('refuses a move the lifecycle does not declare', function () {
        [$url, $payload] = catalogStatusMove($this->product, 'active');

        $this->actingAs($this->manager)->patch($url, $payload)->assertSessionHasErrors('status');

        expect($this->product->refresh()->status)->toBe(ProductStatus::Draft)
            ->and(ProductStatusChange::query()->count())->toBe(0);
    });

    it('refuses a channel status as a lifecycle move', function () {
        [$url, $payload] = catalogStatusMove($this->product, 'wholesale_enabled');

        $this->actingAs($this->manager)->patch($url, $payload)->assertSessionHasErrors('status');

        expect(fn () => app(TransitionProduct::class)->handle($this->manager, $this->product, ProductStatus::WholesaleEnabled))
            ->toThrow(CatalogRefused::class);
    });

    it('asks why a product is being retired', function () {
        app(TransitionProduct::class)->handle($this->manager, $this->product, ProductStatus::PendingReview);

        [$url, $payload] = catalogStatusMove($this->product, 'archived');
        $this->actingAs($this->manager)->patch($url, $payload)->assertSessionHasErrors('status');

        [$url, $payload] = catalogStatusMove($this->product, 'archived', 'Supplier stopped making it.');
        $this->actingAs($this->manager)->patch($url, $payload)->assertSessionHasNoErrors();

        expect($this->product->refresh()->status)->toBe(ProductStatus::Archived)
            ->and($this->product->statusHistory()->first()?->reason)->toBe('Supplier stopped making it.');
    });

    it('returns an archived product only to draft, never straight to sale', function () {
        expect(ProductStatus::Archived->transitionsTo())->toBe([ProductStatus::Draft]);
    });
});

describe('ready to activate', function () {
    beforeEach(function () {
        app(TransitionProduct::class)->handle($this->manager, $this->product, ProductStatus::PendingReview);
        $this->product->refresh();
    });

    it('refuses a product without a wholesale price', function () {
        $this->product->forceFill(['wholesale_price_minor' => 0])->save();

        expect(fn () => app(TransitionProduct::class)->handle($this->manager, $this->product, ProductStatus::Active))
            ->toThrow(CatalogRefused::class, 'no wholesale price');
    });

    it('refuses a product in a switched-off category, or of a switched-off brand', function () {
        $this->product->category->forceFill(['is_active' => false])->save();
        $brand = Brand::create(['name' => 'Walton', 'is_active' => false]);
        $this->product->forceFill(['brand_id' => $brand->id])->save();

        expect(fn () => app(TransitionProduct::class)->handle($this->manager, $this->product->refresh(), ProductStatus::Active))
            ->toThrow(CatalogRefused::class, 'category is switched off; its brand is switched off');
    });

    it('refuses a product whose every variation is switched off', function () {
        ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'FW-RC-M', 'combination_key' => 'k', 'is_active' => false]);

        expect(fn () => app(TransitionProduct::class)->handle($this->manager, $this->product, ProductStatus::Active))
            ->toThrow(CatalogRefused::class, 'every one of its variations is switched off');
    });
});

describe('who may move a product (§12)', function () {
    it('refuses a business account holder', function () {
        [$url, $payload] = catalogStatusMove($this->product, 'pending_review');

        $this->actingAs(testBusinessAccount(AccountStatus::Active)->owner)->patch($url, $payload)->assertForbidden();

        expect($this->product->refresh()->status)->toBe(ProductStatus::Draft);
    });

    it('lets somebody who may edit submit for review, but not publish', function () {
        // An administrator manages the catalogue but holds no publish permission.
        $editor = testPlatformStaff(PlatformRole::Admin);

        [$url, $payload] = catalogStatusMove($this->product, 'pending_review');
        $this->actingAs($editor)->patch($url, $payload)->assertSessionHasNoErrors();

        [$url, $payload] = catalogStatusMove($this->product, 'active');
        $this->actingAs($editor)->patch($url, $payload)->assertForbidden();

        expect($this->product->refresh()->status)->toBe(ProductStatus::PendingReview);
    });

    it('checks the permission in the action too, not only at the door', function () {
        $editor = testPlatformStaff(PlatformRole::Admin);
        app(TransitionProduct::class)->handle($this->manager, $this->product, ProductStatus::PendingReview);

        expect(fn () => app(TransitionProduct::class)->handle($editor, $this->product->refresh(), ProductStatus::Active))
            ->toThrow(AuthorizationException::class);
    });

    it('offers on the editor only the moves this person may make', function () {
        app(TransitionProduct::class)->handle($this->manager, $this->product, ProductStatus::PendingReview);

        $this->actingAs(testPlatformStaff(PlatformRole::Admin))
            ->get(route('admin.catalog.products.edit', $this->product->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.status', 'pending_review')
                ->where('product.status_tone', 'info')
                ->where('transitions', [['value' => 'draft', 'tone' => 'neutral', 'requires_reason' => false]])
                ->where('history.0.to', 'pending_review'),
            );

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.products.edit', $this->product->public_id))
            ->assertInertia(fn (Assert $page) => $page->has('transitions', 3));
    });
});

describe('the record of every move', function () {
    it('cannot be edited or deleted', function () {
        app(TransitionProduct::class)->handle($this->manager, $this->product, ProductStatus::PendingReview);

        expect(fn () => DB::table('product_status_history')->update(['reason' => 'rewritten']))
            ->toThrow(QueryException::class);
    });

    it('keeps a draft that has been through review from being deleted', function () {
        app(TransitionProduct::class)->handle($this->manager, $this->product, ProductStatus::PendingReview);
        app(TransitionProduct::class)->handle($this->manager, $this->product->refresh(), ProductStatus::Draft);

        expect(fn () => app(ManageProducts::class)->delete($this->manager, $this->product->refresh()))
            ->toThrow(CatalogRefused::class, 'already been through review');
    });
});
