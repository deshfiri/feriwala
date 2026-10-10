<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Sourcing\Models\ProductLink;
use App\Domain\Sourcing\Queries\ResolveProductNetwork;
use App\Domain\Supplier\Actions\DecideSupplierListingLot;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Enums\LotStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/*
 * Supplier listing review: a Supplier's Product may be approved while staying
 * unique, or linked with one or more existing Products staff confirm are the
 * same physical Product. Nothing is ever linked automatically.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->reviewer = testPlatformStaff(PlatformRole::SupplierManager);
    $this->reviewer->assignRole(PlatformRole::ProductManager->value);
    $this->network = app(ResolveProductNetwork::class);
});

function listingReviewDecide(array $overrides = [], ?string $itemId = null): TestResponse
{
    $listing = test()->listing;
    $item = $listing->items()->firstOrFail();

    return test()->actingAs(test()->reviewer)->post(route('admin.supplier-listings.decision.store', $listing), [
        'reason' => 'Approved.',
        'items' => [[
            'item_id' => $itemId ?? $item->public_id,
            'decision' => 'approve',
            'platform_rate' => '1300.00',
            ...($overrides['item'] ?? []),
        ]],
        ...array_diff_key($overrides, ['item' => true]),
    ]);
}

describe('keeping the Product unique, or linking it', function () {
    beforeEach(function () {
        $this->listing = supplierTestListing(Supplier::factory()->create());
        $this->product = websiteTestProduct(['name' => 'Cotton Pants']);
    });

    it('approves without any link: the Product stays unique', function () {
        listingReviewDecide(['connect_product_id' => $this->product->public_id])->assertSessionHasNoErrors();

        expect(SupplierOffer::query()->count())->toBe(1)
            ->and($this->listing->fresh()->status)->toBe(ListingStatus::Approved)
            ->and(ProductLink::query()->count())->toBe(0);
    });

    it('never links on a guess, even to a Product with the same name', function () {
        websiteTestProduct(['name' => 'Cotton Pants']);

        listingReviewDecide(['connect_product_id' => $this->product->public_id])->assertSessionHasNoErrors();

        expect(ProductLink::query()->count())->toBe(0);
    });

    it('links the approved Product with every Product the reviewer picked, audited with the reason', function () {
        $first = websiteTestProduct(['name' => 'Cotton Trousers']);
        $second = websiteTestProduct(['name' => 'Men Cotton Pants']);

        listingReviewDecide([
            'connect_product_id' => $this->product->public_id,
            'link_product_ids' => [$first->public_id, $second->public_id],
        ])->assertSessionHasNoErrors();

        expect(array_keys($this->network->network($this->product->id)))->toEqualCanonicalizing([$first->id, $second->id])
            ->and(ProductLink::query()->count())->toBe(2)
            ->and(ProductLink::query()->first()->linked_by)->toBe($this->reviewer->id)
            ->and(AuditLog::query()->where('action', 'product_link.linked')->value('reason'))->toBe('Approved.');
    });

    it('leaves a pair that is already linked as it is', function () {
        $other = websiteTestProduct(['name' => 'Cotton Trousers']);

        listingReviewDecide(['connect_product_id' => $this->product->public_id, 'link_product_ids' => [$other->public_id]])
            ->assertSessionHasNoErrors();

        $second = supplierTestListing(Supplier::factory()->create());
        $this->listing = $second;

        listingReviewDecide(['connect_product_id' => $this->product->public_id, 'link_product_ids' => [$other->public_id]])
            ->assertSessionHasNoErrors();

        expect(ProductLink::query()->count())->toBe(1)
            ->and(SupplierOffer::query()->count())->toBe(2);
    });

    it('refuses linking a Product to itself, or to one that does not exist, and approves nothing', function () {
        listingReviewDecide(['connect_product_id' => $this->product->public_id, 'link_product_ids' => [$this->product->public_id]])
            ->assertSessionHasErrors('reason');

        listingReviewDecide(['connect_product_id' => $this->product->public_id, 'link_product_ids' => ['does-not-exist']])
            ->assertSessionHasErrors('reason');

        expect(SupplierOffer::query()->count())->toBe(0)
            ->and(ProductLink::query()->count())->toBe(0)
            ->and($this->listing->fresh()->status)->toBe(ListingStatus::UnderReview);
    });

    it('refuses a reviewer who may not link Products, and approves nothing', function () {
        $other = websiteTestProduct();
        $staff = testPlatformStaff(PlatformRole::SupplierManager);
        $staff->assignRole(PlatformRole::ProductManager->value);
        $staff->roles->each(fn ($role) => $role->revokePermissionTo('product_link.create'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->reviewer = $staff->fresh();

        listingReviewDecide(['connect_product_id' => $this->product->public_id, 'link_product_ids' => [$other->public_id]])
            ->assertSessionHasErrors('reason');

        expect(session('errors')->first('reason'))->toContain('may not link')
            ->and(SupplierOffer::query()->count())->toBe(0);

        // The same reviewer can still approve while keeping the Product unique.
        listingReviewDecide(['connect_product_id' => $this->product->public_id])->assertSessionHasNoErrors();

        expect(SupplierOffer::query()->count())->toBe(1);
    });

    it('leaves a linked Product\'s variations unmatched until staff match them', function () {
        $other = websiteTestProduct(['name' => 'Cotton Trousers']);
        $variant = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'CP-M', 'combination_key' => 'm']);
        $otherVariant = ProductVariant::create(['product_id' => $other->id, 'sku' => 'CT-M', 'combination_key' => 'm']);

        listingReviewDecide([
            'connect_product_id' => $this->product->public_id,
            'link_product_ids' => [$other->public_id],
            'item' => ['variant_id' => $variant->public_id],
        ])->assertSessionHasNoErrors();

        expect($this->network->compatiblePairs($this->product->id, $variant->id))->toBe([[$this->product->id, $variant->id]])
            ->and($this->network->compatiblePairs($other->id, $otherVariant->id))->toBe([[$other->id, $otherVariant->id]]);
    });
});

describe('lot review and access', function () {
    it('links a lot entry the same way, and works without a link', function () {
        $supplier = Supplier::factory()->create();
        $lot = $supplier->lots()->create(['status' => LotStatus::UnderReview, 'submitted_at' => now()]);
        $entry = $lot->items()->create([
            'supplier_id' => $supplier->id, 'product_name' => 'Cotton', 'category_suggestion' => 'Menswear',
            'status' => ListingStatus::UnderReview, 'submitted_at' => now(),
        ]);
        $entry->items()->create([
            'supplier_sku' => 'SUP-1', 'supplier_rate' => Money::fromDecimal('1000.00', Currency::BDT),
            'currency_code' => 'BDT', 'available_quantity' => 5, 'minimum_supply_quantity' => 1,
        ]);
        $product = websiteTestProduct();
        $existing = websiteTestProduct();
        $itemId = $entry->items()->firstOrFail()->public_id;

        $results = app(DecideSupplierListingLot::class)->handle($lot->refresh(), $this->reviewer, [[
            'listing_id' => $entry->public_id, 'reason' => 'Ok.',
            'product' => [...supplierTestConnect($product), 'link_product_ids' => [$existing->public_id]],
            'items' => [['item_id' => $itemId, 'decision' => 'approve', 'platform_rate' => '1300.00']],
        ]]);

        expect($results[0]['ok'])->toBeTrue()
            ->and(array_keys($this->network->network($product->id)))->toBe([$existing->id]);
    });

    it('tells the review page whether this reviewer may link, and nobody else sees it', function () {
        $listing = supplierTestListing(Supplier::factory()->create());

        $this->actingAs($this->reviewer)->get(route('admin.supplier-listings.show', $listing))
            ->assertInertia(fn (Assert $page) => $page->where('can_link_products', true));

        $viewer = testPlatformStaff(PlatformRole::SupplierManager);
        $viewer->roles->each(fn ($role) => $role->revokePermissionTo('product_link.create'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($viewer->fresh())->get(route('admin.supplier-listings.show', $listing))
            ->assertInertia(fn (Assert $page) => $page->where('can_link_products', false));
    });

    it('keeps a Supplier session off the review page and the Product search', function () {
        $listing = supplierTestListing(Supplier::factory()->create());

        supplierTestSignIn($listing->supplier);

        $this->get(route('admin.supplier-listings.show', $listing))->assertRedirect(route('login'));
        $this->getJson(route('admin.catalog.product-links.search', ['q' => 'cotton']))->assertUnauthorized();
    });
});
