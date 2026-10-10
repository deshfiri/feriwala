<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Sourcing\Actions\ManageSourcingGroups;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Sourcing groups no longer decide where an order line can be fulfilled, so a
 * new line freezes nothing about them. The columns stay, nullable and locked,
 * because lines written while groups drove allocation still carry them.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = testPlatformStaff(PlatformRole::SuperAdmin);
});

function orderItemSnapshotLine(): OrderItem
{
    static $number = 0;

    $product = websiteTestProduct();
    $order = Order::factory()->create();
    $money = Money::fromDecimal('2000.00', Currency::BDT);

    return $order->items()->create([
        'line_number' => ++$number,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'product_name' => $product->name,
        'quantity' => 1,
        'currency_code' => 'BDT',
        'unit_price' => $money,
        'line_subtotal' => $money,
        'line_total' => $money,
    ])->refresh();
}

it('freezes no sourcing group on a new line, even for a Product that sits in an old group', function () {
    $groups = app(ManageSourcingGroups::class);
    $group = $groups->create($this->staff, ['code' => 'regular-pants', 'name_en' => 'Regular pants', 'name_bn' => 'রেগুলার প্যান্ট']);
    $product = websiteTestProduct();
    $groups->addProduct($this->staff, $group, $product, 'Reference.');

    $order = Order::factory()->create();
    $money = Money::fromDecimal('2000.00', Currency::BDT);
    $line = $order->items()->create([
        'line_number' => 1, 'product_id' => $product->id, 'sku' => $product->sku, 'product_name' => $product->name,
        'quantity' => 1, 'currency_code' => 'BDT', 'unit_price' => $money, 'line_subtotal' => $money, 'line_total' => $money,
    ])->refresh();

    expect($line->sourcing_group_id)->toBeNull()
        ->and($line->sourcing_canonical_product_id)->toBeNull()
        ->and($line->sourcing_canonical_variant_id)->toBeNull();
});

it('cannot be altered after the line is written, even directly in the database', function () {
    $line = orderItemSnapshotLine();

    expect(fn () => DB::table('order_items')->where('id', $line->id)->update(['product_name' => 'Changed']))
        ->toThrow(QueryException::class);
});

it('still refuses an incoherent legacy snapshot at the database', function () {
    $order = Order::factory()->create();
    $group = app(ManageSourcingGroups::class)->create($this->staff, ['code' => 'legacy', 'name_en' => 'Legacy', 'name_bn' => 'লিগ্যাসি']);

    expect(fn () => DB::table('order_items')->insert([
        'public_id' => (string) Str::ulid(), 'order_id' => $order->id, 'line_number' => 1,
        'product_id' => websiteTestProduct()->id, 'sku' => 'X', 'product_name' => 'X', 'quantity' => 1, 'currency_code' => 'BDT',
        'unit_price' => '1.00', 'line_subtotal' => '1.00', 'line_total' => '1.00', 'created_at' => now(),
        'sourcing_group_id' => $group->id, 'sourcing_canonical_product_id' => null,
    ]))->toThrow(QueryException::class);
});
