<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Sourcing\Actions\ManageSourcingGroups;
use App\Domain\Sourcing\Models\ProductSourcingGroup;
use App\Domain\Sourcing\Models\ProductSourcingGroupProduct;
use App\Domain\Sourcing\Models\ProductSourcingVariantMapping;
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
 * Supplier listing review: every approval needs a staff-chosen sourcing group,
 * and variations are mapped explicitly -- never matched by label.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->reviewer = testPlatformStaff(PlatformRole::SupplierManager);
    $this->reviewer->assignRole(PlatformRole::ProductManager->value);
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

describe('selecting a group', function () {
    beforeEach(function () {
        $this->listing = supplierTestListing(Supplier::factory()->create());
        $this->product = websiteTestProduct(['name' => 'Cotton Pants']);
    });

    it('refuses to approve into a product with no group, and creates nothing', function () {
        listingReviewDecide(['connect_product_id' => $this->product->public_id])
            ->assertSessionHasErrors('reason');

        expect(session('errors')->first('reason'))->toContain('Select a sourcing group')
            ->and(SupplierOffer::query()->count())->toBe(0)
            ->and($this->listing->fresh()->status)->toBe(ListingStatus::UnderReview);
    });

    it('puts the product into the chosen group on approval, audited with the reason', function () {
        $group = supplierTestSourcingGroup();

        listingReviewDecide(['connect_product_id' => $this->product->public_id, 'sourcing_group_id' => $group->public_id])
            ->assertSessionHasNoErrors();

        $membership = ProductSourcingGroupProduct::query()->active()->where('product_id', $this->product->id)->firstOrFail();

        expect($membership->sourcing_group_id)->toBe($group->id)
            ->and($membership->is_canonical)->toBeTrue()
            ->and(SupplierOffer::query()->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'sourcing_group.product_added')->value('reason'))->toBe('Approved.');
    });

    it('keeps the group a product is already in, and refuses a different one', function () {
        $existing = supplierTestSourcingGroup();
        $other = supplierTestSourcingGroup();
        app(ManageSourcingGroups::class)->addProduct($this->reviewer, $existing, $this->product, 'Reference.');

        listingReviewDecide(['connect_product_id' => $this->product->public_id, 'sourcing_group_id' => $other->public_id])
            ->assertSessionHasErrors('reason');

        expect(SupplierOffer::query()->count())->toBe(0);

        listingReviewDecide(['connect_product_id' => $this->product->public_id])->assertSessionHasNoErrors();

        expect(SupplierOffer::query()->count())->toBe(1);
    });

    it('refuses an inactive or unknown group', function () {
        $inactive = supplierTestSourcingGroup();
        app(ManageSourcingGroups::class)->setActive($this->reviewer, $inactive, false, 'Paused.');

        listingReviewDecide(['connect_product_id' => $this->product->public_id, 'sourcing_group_id' => $inactive->public_id])
            ->assertSessionHasErrors('reason');

        listingReviewDecide(['connect_product_id' => $this->product->public_id, 'sourcing_group_id' => 'does-not-exist'])
            ->assertSessionHasErrors('reason');

        expect(SupplierOffer::query()->count())->toBe(0);
    });

    it('lets a group be created and selected without leaving the review', function () {
        $created = $this->actingAs($this->reviewer)->postJson(route('admin.sourcing-groups.quick-store'), [
            'code' => 'cotton-pants', 'name_en' => 'Cotton pants', 'name_bn' => 'কটন প্যান্ট',
        ])->assertCreated()->assertJsonPath('code', 'cotton-pants')->json();

        listingReviewDecide(['connect_product_id' => $this->product->public_id, 'sourcing_group_id' => $created['id']])
            ->assertSessionHasNoErrors();

        expect(ProductSourcingGroup::query()->where('public_id', $created['id'])->firstOrFail()->products()->active()->count())->toBe(1);
    });

    it('refuses a quick-created group with a duplicate or malformed code', function () {
        supplierTestSourcingGroup();
        $existing = ProductSourcingGroup::query()->firstOrFail();

        $this->actingAs($this->reviewer)->postJson(route('admin.sourcing-groups.quick-store'), [
            'code' => $existing->code, 'name_en' => 'X', 'name_bn' => 'এক্স',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->postJson(route('admin.sourcing-groups.quick-store'), ['code' => 'Bad Code', 'name_en' => 'X', 'name_bn' => 'এক্স'])
            ->assertUnprocessable();
    });

    it('does not let a reviewer without sourcing permission pick a group', function () {
        $group = supplierTestSourcingGroup();
        $staff = testPlatformStaff(PlatformRole::SupplierManager);
        $staff->assignRole(PlatformRole::ProductManager->value);
        $staff->revokePermissionTo('sourcing_group.edit');
        $staff->roles()->each(fn ($role) => $role->revokePermissionTo('sourcing_group.edit'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->reviewer = $staff->fresh();

        listingReviewDecide(['connect_product_id' => $this->product->public_id, 'sourcing_group_id' => $group->public_id])
            ->assertSessionHasErrors('reason');

        expect(session('errors')->first('reason'))->toContain('may not add products')
            ->and(SupplierOffer::query()->count())->toBe(0);
    });
});

describe('variant compatibility', function () {
    beforeEach(function () {
        $this->manage = app(ManageSourcingGroups::class);
        $this->group = supplierTestSourcingGroup();
        $this->canonical = websiteTestProduct(['name' => 'Regular Pants']);
        $this->member = websiteTestProduct(['name' => 'Cotton Pants']);
        $this->manage->addProduct($this->reviewer, $this->group, $this->canonical, 'Reference.');
        $this->canonicalM = ProductVariant::create(['product_id' => $this->canonical->id, 'sku' => 'RP-M', 'combination_key' => 'm']);
        $this->canonicalL = ProductVariant::create(['product_id' => $this->canonical->id, 'sku' => 'RP-L', 'combination_key' => 'l']);
        $this->memberM = ProductVariant::create(['product_id' => $this->member->id, 'sku' => 'CP-M', 'combination_key' => 'm']);
        $this->listing = supplierTestListing(Supplier::factory()->create());
    });

    it('needs an explicit canonical variation for a member product, never a label match', function () {
        $base = [
            'connect_product_id' => $this->member->public_id,
            'sourcing_group_id' => $this->group->public_id,
        ];

        listingReviewDecide([...$base, 'item' => ['variant_id' => $this->memberM->public_id]])
            ->assertSessionHasErrors('reason');

        expect(session('errors')->first('reason'))->toContain('Choose which canonical variation')
            ->and(SupplierOffer::query()->count())->toBe(0);

        listingReviewDecide([...$base, 'item' => [
            'variant_id' => $this->memberM->public_id,
            'canonical_variant_id' => $this->canonicalM->public_id,
        ]])->assertSessionHasNoErrors();

        $mapping = ProductSourcingVariantMapping::query()->active()->where('product_variant_id', $this->memberM->id)->firstOrFail();

        expect($mapping->canonical_product_variant_id)->toBe($this->canonicalM->id)
            ->and(SupplierOffer::query()->count())->toBe(1);
    });

    it('refuses a canonical variation that belongs to another product', function () {
        $stranger = ProductVariant::create(['product_id' => websiteTestProduct()->id, 'sku' => 'ZZ-M', 'combination_key' => 'm']);

        listingReviewDecide([
            'connect_product_id' => $this->member->public_id,
            'sourcing_group_id' => $this->group->public_id,
            'item' => ['variant_id' => $this->memberM->public_id, 'canonical_variant_id' => $stranger->public_id],
        ])->assertSessionHasErrors('reason');

        expect(SupplierOffer::query()->count())->toBe(0);
    });

    it('reuses an existing mapping without asking again', function () {
        $this->manage->addProduct($this->reviewer, $this->group, $this->member, 'Equivalent.');
        $this->manage->mapVariant($this->reviewer, $this->group, $this->member, $this->memberM, $this->canonicalL, 'Both are the longer cut.');

        listingReviewDecide([
            'connect_product_id' => $this->member->public_id,
            'item' => ['variant_id' => $this->memberM->public_id],
        ])->assertSessionHasNoErrors();

        expect(ProductSourcingVariantMapping::query()->where('product_variant_id', $this->memberM->id)->count())->toBe(1);
    });

    it('needs no mapping for the canonical product itself', function () {
        listingReviewDecide([
            'connect_product_id' => $this->canonical->public_id,
            'item' => ['variant_id' => $this->canonicalM->public_id],
        ])->assertSessionHasNoErrors();

        expect(ProductSourcingVariantMapping::query()->count())->toBe(0)
            ->and(SupplierOffer::query()->count())->toBe(1);
    });

    it('maps a product without variations at product level automatically', function () {
        $plainGroup = supplierTestSourcingGroup();
        $plainCanonical = websiteTestProduct();
        $plain = websiteTestProduct();
        $this->manage->addProduct($this->reviewer, $plainGroup, $plainCanonical, 'Reference.');

        listingReviewDecide([
            'connect_product_id' => $plain->public_id,
            'sourcing_group_id' => $plainGroup->public_id,
        ])->assertSessionHasNoErrors();

        expect(ProductSourcingVariantMapping::query()->active()->where('product_id', $plain->id)->whereNull('product_variant_id')->count())->toBe(1);
    });
});

describe('lot review and access', function () {
    it('applies the same rule to a lot entry', function () {
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
        $itemId = $entry->items()->firstOrFail()->public_id;

        $refused = app(DecideSupplierListingLot::class)->handle($lot->refresh(), $this->reviewer, [[
            'listing_id' => $entry->public_id, 'reason' => 'Ok.', 'product' => ['connect_product_id' => $product->public_id],
            'items' => [['item_id' => $itemId, 'decision' => 'approve', 'platform_rate' => '1300.00']],
        ]]);

        expect($refused[0]['ok'])->toBeFalse()->and($refused[0]['message'])->toContain('Select a sourcing group');

        $accepted = app(DecideSupplierListingLot::class)->handle($lot->refresh(), $this->reviewer, [[
            'listing_id' => $entry->public_id, 'reason' => 'Ok.', 'product' => supplierTestConnect($product),
            'items' => [['item_id' => $itemId, 'decision' => 'approve', 'platform_rate' => '1300.00']],
        ]]);

        expect($accepted[0]['ok'])->toBeTrue();
    });

    it('shows staff the selectable groups on the review page and nobody else', function () {
        $group = supplierTestSourcingGroup();
        $listing = supplierTestListing(Supplier::factory()->create());

        $this->actingAs($this->reviewer)->get(route('admin.supplier-listings.show', $listing))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sourcing.can_select', true)
                ->where('sourcing.can_create', true)
                ->has('sourcing.groups', 1)
                ->where('sourcing.groups.0.id', $group->public_id));
    });

    it('keeps a Supplier session off the review page and the group endpoint', function () {
        $listing = supplierTestListing(Supplier::factory()->create());

        supplierTestSignIn($listing->supplier);

        $this->get(route('admin.supplier-listings.show', $listing))->assertRedirect(route('login'));
        $this->postJson(route('admin.sourcing-groups.quick-store'), ['code' => 'x', 'name_en' => 'X', 'name_bn' => 'এক্স'])
            ->assertUnauthorized();
    });

    it('refuses quick-creating a group to view-only staff', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::Admin))
            ->postJson(route('admin.sourcing-groups.quick-store'), ['code' => 'x', 'name_en' => 'X', 'name_bn' => 'এক্স'])
            ->assertForbidden();
    });
});
