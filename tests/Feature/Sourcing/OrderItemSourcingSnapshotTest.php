<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Sourcing\Actions\ManageSourcingGroups;
use App\Domain\Sourcing\Queries\ResolveSourcingRequirement;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * An order line freezes what it requires of its fulfilment -- the sourcing
 * group and the canonical product/variation -- when it is written. Later
 * mapping changes never rewrite it, and a line with no explicit mapping is
 * left unmatched rather than guessed.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = testPlatformStaff(PlatformRole::SuperAdmin);
    $this->manage = app(ManageSourcingGroups::class);

    $this->group = $this->manage->create($this->staff, ['code' => 'regular-pants', 'name_en' => 'Regular pants', 'name_bn' => 'রেগুলার প্যান্ট']);
    $this->canonical = websiteTestProduct(['name' => 'Regular Pants']);
    $this->member = websiteTestProduct(['name' => 'Cotton Pants']);
    $this->manage->addProduct($this->staff, $this->group, $this->canonical, 'Reference.');
    $this->manage->addProduct($this->staff, $this->group, $this->member, 'Equivalent.');

    $this->canonicalM = ProductVariant::create(['product_id' => $this->canonical->id, 'sku' => 'RP-M', 'combination_key' => 'm']);
    $this->canonicalL = ProductVariant::create(['product_id' => $this->canonical->id, 'sku' => 'RP-L', 'combination_key' => 'l']);
    $this->memberM = ProductVariant::create(['product_id' => $this->member->id, 'sku' => 'CP-M', 'combination_key' => 'm']);
});

function sourcingSnapshotLine(Product $product, ?ProductVariant $variant = null): OrderItem
{
    static $number = 0;

    $order = Order::factory()->create();
    $money = Money::fromDecimal('2000.00', Currency::BDT);

    return $order->items()->create([
        'line_number' => ++$number,
        'product_id' => $product->id,
        'product_variant_id' => $variant?->id,
        'sku' => $variant?->sku ?? $product->sku,
        'product_name' => $product->name,
        'quantity' => 1,
        'currency_code' => 'BDT',
        'unit_price' => $money,
        'line_subtotal' => $money,
        'line_total' => $money,
    ])->refresh();
}

it('freezes the group and canonical requirement for a canonical product', function () {
    $line = sourcingSnapshotLine($this->canonical, $this->canonicalM);

    expect($line->sourcing_group_id)->toBe($this->group->id)
        ->and($line->sourcing_canonical_product_id)->toBe($this->canonical->id)
        ->and($line->sourcing_canonical_variant_id)->toBe($this->canonicalM->id);
});

it('freezes the mapped canonical variation for a member product', function () {
    $this->manage->mapVariant($this->staff, $this->group, $this->member, $this->memberM, $this->canonicalL, 'Both are the longer cut.');

    $line = sourcingSnapshotLine($this->member, $this->memberM);

    expect($line->sourcing_group_id)->toBe($this->group->id)
        ->and($line->sourcing_canonical_product_id)->toBe($this->canonical->id)
        ->and($line->sourcing_canonical_variant_id)->toBe($this->canonicalL->id);
});

it('leaves a line unmatched when nothing explicit says what it requires', function (string $case) {
    $line = match ($case) {
        'in no group' => sourcingSnapshotLine(websiteTestProduct()),
        'member variation without a mapping' => sourcingSnapshotLine($this->member, $this->memberM),
        'group inactive' => (function () {
            $this->manage->setActive($this->staff, $this->group, false, 'Paused.');

            return sourcingSnapshotLine($this->canonical, $this->canonicalM);
        })(),
    };

    expect($line->sourcing_group_id)->toBeNull()
        ->and($line->sourcing_canonical_product_id)->toBeNull()
        ->and($line->sourcing_canonical_variant_id)->toBeNull();
})->with(['in no group', 'member variation without a mapping', 'group inactive']);

it('never changes an existing line when mappings, membership or the group change later', function () {
    $mapping = $this->manage->mapVariant($this->staff, $this->group, $this->member, $this->memberM, $this->canonicalL, 'Longer cut.');
    $line = sourcingSnapshotLine($this->member, $this->memberM);

    $this->manage->unmapVariant($this->staff, $mapping, 'Wrong.');
    $this->manage->mapVariant($this->staff, $this->group, $this->member, $this->memberM, $this->canonicalM, 'Corrected.');
    $this->manage->removeProduct($this->staff, $this->group, $this->member, 'No longer equivalent.');
    $this->manage->setActive($this->staff, $this->group, false, 'Retired.');

    $frozen = $line->fresh();

    expect($frozen->sourcing_group_id)->toBe($this->group->id)
        ->and($frozen->sourcing_canonical_variant_id)->toBe($this->canonicalL->id);

    // A new line for the same product now resolves differently -- unmatched.
    expect(sourcingSnapshotLine($this->member, $this->memberM)->sourcing_group_id)->toBeNull();
});

it('does not backfill: lines written before groups existed stay unmatched', function () {
    DB::table('order_items')->insert([
        'public_id' => (string) Str::ulid(),
        'order_id' => Order::factory()->create()->id,
        'line_number' => 1,
        'product_id' => $this->canonical->id,
        'sku' => 'RP-1', 'product_name' => 'Regular Pants', 'quantity' => 1, 'currency_code' => 'BDT',
        'unit_price' => '2000.00', 'line_subtotal' => '2000.00', 'line_total' => '2000.00',
        'created_at' => now(),
    ]);

    expect(OrderItem::query()->firstOrFail()->sourcing_group_id)->toBeNull();
});

it('cannot be altered after the line is written, even directly in the database', function () {
    $line = sourcingSnapshotLine($this->canonical, $this->canonicalM);

    expect(fn () => DB::table('order_items')->where('id', $line->id)->update(['sourcing_group_id' => null]))
        ->toThrow(QueryException::class);
});

it('refuses an incoherent snapshot at the database', function () {
    $line = Order::factory()->create();

    expect(fn () => DB::table('order_items')->insert([
        'public_id' => (string) Str::ulid(), 'order_id' => $line->id, 'line_number' => 1,
        'product_id' => $this->canonical->id, 'sku' => 'X', 'product_name' => 'X', 'quantity' => 1, 'currency_code' => 'BDT',
        'unit_price' => '1.00', 'line_subtotal' => '1.00', 'line_total' => '1.00', 'created_at' => now(),
        'sourcing_group_id' => $this->group->id, 'sourcing_canonical_product_id' => null,
    ]))->toThrow(QueryException::class);
});

it('resolves from the live mappings only when asked, so the answer is never cached', function () {
    $resolver = app(ResolveSourcingRequirement::class);

    expect($resolver->forLine($this->member->id, $this->memberM->id))->toBeNull();

    $this->manage->mapVariant($this->staff, $this->group, $this->member, $this->memberM, $this->canonicalM, 'Size M.');

    expect($resolver->forLine($this->member->id, $this->memberM->id)?->canonicalVariantId)->toBe($this->canonicalM->id);
});
