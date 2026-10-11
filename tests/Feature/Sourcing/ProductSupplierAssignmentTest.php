<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Sourcing\Actions\ManageProductSuppliers;
use App\Domain\Sourcing\Enums\SourcingMappingStatus;
use App\Domain\Sourcing\Models\ProductSupplierAssignment;
use App\Domain\Sourcing\Queries\ProductSuppliersSection;
use App\Domain\Supplier\Models\Supplier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Which Suppliers actually have which Product: a data-only record. Many Suppliers
 * per Product, one active record per pair, removal kept as history, and a Supplier
 * of a Product in the same Same Product group shown for reference.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = testPlatformStaff(PlatformRole::ProductManager);
    $this->product = websiteTestProduct(['name' => 'Cotton Pants']);
    $this->other = websiteTestProduct(['name' => 'Cotton Trousers']);
    $this->first = Supplier::factory()->create(['business_name' => 'Alpha Textiles']);
    $this->second = Supplier::factory()->create(['business_name' => 'Beta Garments']);
});

it('lets one Product have many Suppliers and refuses the same pair twice', function () {
    $assign = app(ManageProductSuppliers::class);

    $assign->assign($this->staff, $this->product, $this->first, 'ALP-1', 'Confirmed on call');
    $assign->assign($this->staff, $this->product, $this->second);

    expect(ProductSupplierAssignment::query()->active()->where('product_id', $this->product->id)->count())->toBe(2)
        ->and(ProductSupplierAssignment::query()->first()->supplier_reference)->toBe('ALP-1')
        ->and(fn () => $assign->assign($this->staff, $this->product, $this->first))->toThrow(InvalidArgumentException::class);
});

it('keeps a removed assignment as history and allows assigning again', function () {
    $assign = app(ManageProductSuppliers::class);
    $assignment = $assign->assign($this->staff, $this->product, $this->first);

    $assign->remove($this->staff, $assignment, 'Stopped stocking it');

    expect($assignment->fresh()->status)->toBe(SourcingMappingStatus::Removed)
        ->and($assignment->fresh()->removal_reason)->toBe('Stopped stocking it');

    $assign->assign($this->staff, $this->product, $this->first);

    expect(ProductSupplierAssignment::query()->count())->toBe(2)
        ->and(ProductSupplierAssignment::query()->active()->count())->toBe(1);
});

it('never deletes an assignment', function () {
    app(ManageProductSuppliers::class)->assign($this->staff, $this->product, $this->first);

    expect(fn () => DB::table('product_supplier_assignments')->delete())->toThrow(Exception::class);
});

it('refuses staff without the permission and business accounts', function () {
    $sms = testPlatformStaff(PlatformRole::SmsManager);

    expect(fn () => app(ManageProductSuppliers::class)->assign($sms, $this->product, $this->first))
        ->toThrow(AuthorizationException::class);

    $this->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
        ->getJson(route('admin.product-suppliers.suppliers'))->assertForbidden();
});

it('assigns several Suppliers to a Product over HTTP and rejects an unapproved one', function () {
    $this->actingAs($this->staff)->post(route('admin.product-suppliers.store'), [
        'product_ids' => [$this->product->public_id],
        'supplier_ids' => [$this->first->public_id, $this->second->public_id],
    ])->assertRedirect();

    expect(ProductSupplierAssignment::query()->active()->count())->toBe(2);

    $draft = Supplier::factory()->draft()->create();

    $this->post(route('admin.product-suppliers.store'), [
        'product_ids' => [$this->other->public_id],
        'supplier_ids' => [$draft->public_id],
    ])->assertSessionHasErrors('supplier_ids');

    expect(ProductSupplierAssignment::query()->count())->toBe(2);
});

it('assigns one Supplier to several Products and removes an assignment over HTTP', function () {
    $this->actingAs($this->staff)->post(route('admin.product-suppliers.store'), [
        'product_ids' => [$this->product->public_id, $this->other->public_id],
        'supplier_ids' => [$this->first->public_id],
    ])->assertRedirect();

    $assignment = ProductSupplierAssignment::query()->where('product_id', $this->product->id)->firstOrFail();

    $this->delete(route('admin.product-suppliers.destroy', $assignment->public_id), ['reason' => 'Wrong item'])
        ->assertRedirect();

    expect($assignment->fresh()->status)->toBe(SourcingMappingStatus::Removed)
        ->and(ProductSupplierAssignment::query()->active()->count())->toBe(1);
});

it('lists approved Suppliers for the picker, searchable, never an unapproved one', function () {
    Supplier::factory()->draft()->create(['business_name' => 'Alpha Draft']);

    $all = collect($this->actingAs($this->staff)->getJson(route('admin.product-suppliers.suppliers'))->assertOk()->json('data'))->pluck('name')->all();

    expect($all)->toContain('Alpha Textiles', 'Beta Garments')->not->toContain('Alpha Draft');

    $found = $this->getJson(route('admin.product-suppliers.suppliers', ['q' => 'beta']))->json('data');

    expect(collect($found)->pluck('name')->all())->toBe(['Beta Garments']);
});

it('shows the Suppliers of a Product and the Products of a Supplier', function () {
    $assign = app(ManageProductSuppliers::class);
    $assign->assign($this->staff, $this->product, $this->first);
    $assign->assign($this->staff, $this->other, $this->first);

    $this->actingAs(testPlatformStaff(PlatformRole::SuperAdmin))->get(route('admin.catalog.products.edit', $this->product->public_id))
        ->assertInertia(fn (Assert $page) => $page
            ->has('product_suppliers.assigned', 1)
            ->where('product_suppliers.assigned.0.supplier.name', 'Alpha Textiles'));

    $this->get(route('admin.suppliers.show', $this->first))
        ->assertInertia(fn (Assert $page) => $page->has('assigned_products.assigned', 2));
});

it('records the Supplier on a Product when its listing is approved, once and without the assign permission', function () {
    $reviewer = testPlatformStaff(PlatformRole::SmsManager);
    $record = app(ManageProductSuppliers::class);

    $record->recordFromApproval($reviewer, $this->product, $this->first, 'Approved');
    $record->recordFromApproval($reviewer, $this->product, $this->first, 'Approved again');

    $rows = ProductSupplierAssignment::query()->active()->where('product_id', $this->product->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->assign_reason)->toBe('Listing approved. Approved');
});
it('gives nothing to staff who may not view assignments', function () {
    $sms = testPlatformStaff(PlatformRole::SmsManager);

    expect(app(ProductSuppliersSection::class)->forSupplier($this->first, $sms))->toBeNull()
        ->and(app(ProductSuppliersSection::class)->forProduct($this->product, $sms))->toBeNull();
});
