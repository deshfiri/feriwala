<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Sourcing\Actions\ManageSourcingGroups;
use App\Domain\Sourcing\Exceptions\SourcingGroupRefused;
use App\Domain\Sourcing\Models\ProductSourcingGroup;
use App\Domain\Sourcing\Models\ProductSourcingGroupProduct;
use App\Domain\Sourcing\Models\ProductSourcingVariantMapping;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/*
 * Product Sourcing Groups: the schema's own guarantees, the management action's
 * rules, and who may touch them.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = testPlatformStaff(PlatformRole::SuperAdmin);
    $this->manage = app(ManageSourcingGroups::class);
});

function sourcingTestGroup(string $code = 'regular-pants'): ProductSourcingGroup
{
    return app(ManageSourcingGroups::class)->create(
        User::query()->firstOrFail(),
        ['code' => $code, 'name_en' => 'Regular pants', 'name_bn' => 'রেগুলার প্যান্ট'],
    );
}

function sourcingTestVariant(Product $product, string $sku): ProductVariant
{
    return ProductVariant::create(['product_id' => $product->id, 'sku' => $sku, 'combination_key' => $sku]);
}

describe('groups', function () {
    it('creates a group with both names and audits it', function () {
        $group = $this->manage->create($this->staff, [
            'code' => 'regular-pants', 'name_en' => 'Regular pants', 'name_bn' => 'রেগুলার প্যান্ট', 'description' => 'Men',
        ]);

        expect($group->public_id)->not->toBeEmpty()
            ->and($group->is_active)->toBeTrue()
            ->and($group->displayName('bn'))->toBe('রেগুলার প্যান্ট')
            ->and(AuditLog::query()->where('action', 'sourcing_group.created')->where('actor_id', $this->staff->id)->count())->toBe(1);
    });

    it('refuses a duplicate or malformed code at the database', function () {
        $this->manage->create($this->staff, ['code' => 'regular-pants', 'name_en' => 'A', 'name_bn' => 'এ']);

        expect(fn () => $this->manage->create($this->staff, ['code' => 'regular-pants', 'name_en' => 'B', 'name_bn' => 'বি']))
            ->toThrow(QueryException::class);

        expect(fn () => ProductSourcingGroup::factory()->create(['code' => 'Has Spaces']))
            ->toThrow(QueryException::class);
    });

    it('requires a reason to deactivate and records the change', function () {
        $group = $this->manage->create($this->staff, ['code' => 'g1', 'name_en' => 'A', 'name_bn' => 'এ']);

        expect(fn () => $this->manage->setActive($this->staff, $group, false, ' '))
            ->toThrow(SourcingGroupRefused::class);

        $this->manage->setActive($this->staff, $group, false, 'Retired range.');

        expect($group->fresh()->is_active)->toBeFalse()
            ->and(AuditLog::query()->where('action', 'sourcing_group.deactivated')->value('reason'))->toBe('Retired range.');
    });

    it('is never deleted and its code never changes', function () {
        $group = $this->manage->create($this->staff, ['code' => 'g1', 'name_en' => 'A', 'name_bn' => 'এ']);

        expect(fn () => DB::table('product_sourcing_groups')->where('id', $group->id)->delete())->toThrow(QueryException::class);
        expect(fn () => DB::table('product_sourcing_groups')->where('id', $group->id)->update(['code' => 'other']))->toThrow(QueryException::class);
    });
});

describe('membership', function () {
    it('makes the first member canonical and keeps one product in one group', function () {
        $first = sourcingTestGroup('first');
        $second = $this->manage->create($this->staff, ['code' => 'second', 'name_en' => 'B', 'name_bn' => 'বি']);
        $pants = websiteTestProduct(['name' => 'Regular Pants']);
        $cotton = websiteTestProduct(['name' => 'Cotton Pants']);

        $one = $this->manage->addProduct($this->staff, $first, $pants, 'The reference product.');
        $two = $this->manage->addProduct($this->staff, $first, $cotton, 'Same fit and fabric.');

        expect($one->is_canonical)->toBeTrue()->and($two->is_canonical)->toBeFalse();

        expect(fn () => $this->manage->addProduct($this->staff, $second, $cotton, 'Trying another group.'))
            ->toThrow(SourcingGroupRefused::class, 'already in the sourcing group');
    });

    it('refuses new members while inactive and requires a reason', function () {
        $group = sourcingTestGroup();
        $product = websiteTestProduct();

        expect(fn () => $this->manage->addProduct($this->staff, $group, $product, ''))->toThrow(SourcingGroupRefused::class);

        $this->manage->setActive($this->staff, $group, false, 'Paused.');

        expect(fn () => $this->manage->addProduct($this->staff, $group, $product, 'Adding.'))
            ->toThrow(SourcingGroupRefused::class, 'inactive');
    });

    it('removes a member with a reason, keeps the row, and frees the product', function () {
        $group = sourcingTestGroup();
        $canonical = websiteTestProduct();
        $member = websiteTestProduct();
        $this->manage->addProduct($this->staff, $group, $canonical, 'Reference.');
        $this->manage->addProduct($this->staff, $group, $member, 'Equivalent.');

        expect(fn () => $this->manage->removeProduct($this->staff, $group, $canonical, 'Oops.'))
            ->toThrow(SourcingGroupRefused::class, 'canonical');

        $this->manage->removeProduct($this->staff, $group, $member, 'Wrong fit.');

        $row = ProductSourcingGroupProduct::query()->where('product_id', $member->id)->firstOrFail();
        expect($row->isActive())->toBeFalse()->and($row->removal_reason)->toBe('Wrong fit.');

        // Free to join a group again, and the old row is still history.
        $this->manage->addProduct($this->staff, sourcingTestGroup('other'), $member, 'Moved.');
        expect(ProductSourcingGroupProduct::query()->where('product_id', $member->id)->count())->toBe(2);

        expect(fn () => DB::table('product_sourcing_group_products')->where('id', $row->id)->delete())->toThrow(QueryException::class);
    });
});

describe('variant mapping', function () {
    beforeEach(function () {
        $this->group = sourcingTestGroup();
        $this->canonical = websiteTestProduct(['name' => 'Regular Pants']);
        $this->member = websiteTestProduct(['name' => 'Cotton Pants']);
        $this->manage->addProduct($this->staff, $this->group, $this->canonical, 'Reference.');
        $this->manage->addProduct($this->staff, $this->group, $this->member, 'Equivalent.');

        $this->canonicalM = sourcingTestVariant($this->canonical, 'RP-M');
        $this->canonicalL = sourcingTestVariant($this->canonical, 'RP-L');
        $this->memberM = sourcingTestVariant($this->member, 'CP-M');
        $this->memberL = sourcingTestVariant($this->member, 'CP-L');
    });

    it('maps a variation to exactly one canonical variation', function () {
        $mapping = $this->manage->mapVariant($this->staff, $this->group, $this->member, $this->memberM, $this->canonicalM, 'Both are size M.');

        expect($mapping->canonical_product_variant_id)->toBe($this->canonicalM->id);

        expect(fn () => $this->manage->mapVariant($this->staff, $this->group, $this->member, $this->memberM, $this->canonicalL, 'Second guess.'))
            ->toThrow(SourcingGroupRefused::class, 'already has an active mapping');

        // The database refuses it as well, even around the action.
        expect(fn () => ProductSourcingVariantMapping::create([
            'sourcing_group_id' => $this->group->id, 'product_id' => $this->member->id,
            'product_variant_id' => $this->memberM->id, 'canonical_product_variant_id' => $this->canonicalL->id,
            'status' => 'active', 'added_by' => $this->staff->id, 'added_reason' => 'Direct.',
        ]))->toThrow(QueryException::class);
    });

    it('refuses a product-level mapping for a product that has variations', function () {
        expect(fn () => $this->manage->mapVariant($this->staff, $this->group, $this->member, null, $this->canonicalM, 'Lazy.'))
            ->toThrow(SourcingGroupRefused::class, 'has variations');
    });

    it('refuses a variation of some other product on either side', function () {
        $stranger = sourcingTestVariant(websiteTestProduct(), 'ZZ-M');

        expect(fn () => $this->manage->mapVariant($this->staff, $this->group, $this->member, $stranger, $this->canonicalM, 'Wrong.'))
            ->toThrow(SourcingGroupRefused::class, 'does not belong');

        expect(fn () => $this->manage->mapVariant($this->staff, $this->group, $this->member, $this->memberM, $stranger, 'Wrong.'))
            ->toThrow(SourcingGroupRefused::class, 'does not belong');
    });

    it('maps a product without variations at product level', function () {
        $plain = websiteTestProduct();
        $plainCanonicalGroup = sourcingTestGroup('plain');
        $plainCanonical = websiteTestProduct();
        $this->manage->addProduct($this->staff, $plainCanonicalGroup, $plainCanonical, 'Ref.');
        $this->manage->addProduct($this->staff, $plainCanonicalGroup, $plain, 'Eq.');

        $mapping = $this->manage->mapVariant($this->staff, $plainCanonicalGroup, $plain, null, null, 'Single SKU each.');

        expect($mapping->product_variant_id)->toBeNull();

        expect(fn () => $this->manage->mapVariant($this->staff, $plainCanonicalGroup, $plain, null, null, 'Again.'))
            ->toThrow(SourcingGroupRefused::class);
    });

    it('unmaps with a reason and keeps the history, then allows a new mapping', function () {
        $mapping = $this->manage->mapVariant($this->staff, $this->group, $this->member, $this->memberM, $this->canonicalM, 'Size M.');

        $this->manage->unmapVariant($this->staff, $mapping, 'Fits differently.');

        expect($mapping->fresh()->removal_reason)->toBe('Fits differently.');

        $this->manage->mapVariant($this->staff, $this->group, $this->member, $this->memberM, $this->canonicalL, 'Corrected.');

        expect(ProductSourcingVariantMapping::query()->where('product_variant_id', $this->memberM->id)->count())->toBe(2)
            ->and(AuditLog::query()->where('action', 'sourcing_group.variant_unmapped')->exists())->toBeTrue();
    });

    it('removes a product together with its mappings', function () {
        $this->manage->mapVariant($this->staff, $this->group, $this->member, $this->memberM, $this->canonicalM, 'M.');
        $this->manage->mapVariant($this->staff, $this->group, $this->member, $this->memberL, $this->canonicalL, 'L.');

        $this->manage->removeProduct($this->staff, $this->group, $this->member, 'Discontinued.');

        expect(ProductSourcingVariantMapping::query()->active()->where('product_id', $this->member->id)->count())->toBe(0)
            ->and(ProductSourcingVariantMapping::query()->where('product_id', $this->member->id)->count())->toBe(2);
    });
});

describe('permissions', function () {
    it('gives view to admin, manage to product and supplier managers, and nothing to others', function (PlatformRole $role, array $expected) {
        $staff = testPlatformStaff($role);
        $group = sourcingTestGroup();

        expect(Gate::forUser($staff)->allows('viewAny', ProductSourcingGroup::class))->toBe($expected['view'])
            ->and(Gate::forUser($staff)->allows('create', ProductSourcingGroup::class))->toBe($expected['create'])
            ->and(Gate::forUser($staff)->allows('update', $group))->toBe($expected['create'])
            ->and(Gate::forUser($staff)->allows('toggle', $group))->toBe($expected['toggle']);
    })->with([
        'super admin' => [PlatformRole::SuperAdmin, ['view' => true, 'create' => true, 'toggle' => true]],
        'admin' => [PlatformRole::Admin, ['view' => true, 'create' => false, 'toggle' => false]],
        'product manager' => [PlatformRole::ProductManager, ['view' => true, 'create' => true, 'toggle' => true]],
        'supplier manager' => [PlatformRole::SupplierManager, ['view' => true, 'create' => true, 'toggle' => false]],
        'order manager' => [PlatformRole::OrderManager, ['view' => false, 'create' => false, 'toggle' => false]],
        'report viewer' => [PlatformRole::ReportViewer, ['view' => false, 'create' => false, 'toggle' => false]],
    ]);
});
