<?php

namespace Database\Seeders;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Catalog\Enums\AccountScope;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Database\Seeder;

/**
 * One fake, paid wholesale order from Partner 1 with two lines, ready to be
 * reviewed and allocated to a Supplier or the warehouse.
 *
 *     php artisan db:seed --class=FakeOrderSeeder
 *
 * It seeds the five Partners and Suppliers first ({@see FakeDataSeeder}) when
 * they are missing, makes two fake Products if they are not there yet, and adds
 * a new order each time it runs. Like the other fake data it is for trying the
 * screens, not for real data: it books no stock and takes no money.
 */
class FakeOrderSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::query()->where('email', 'partner1@banij.test')->first();

        if ($owner === null) {
            $this->call(FakeDataSeeder::class);
            $owner = User::query()->where('email', 'partner1@banij.test')->firstOrFail();
        }

        $account = BusinessAccount::query()->where('owner_id', $owner->id)->firstOrFail();

        $lines = [
            [$this->product('FAKE-PANJABI', 'Fake cotton panjabi', '1500.00'), 4],
            [$this->product('FAKE-SHIRT', 'Fake denim shirt', '1100.00'), 2],
        ];

        $total = Money::zero(Currency::BDT);

        foreach ($lines as [$product, $quantity]) {
            $total = $total->plus($product->wholesale_price->multipliedBy($quantity));
        }

        $order = Order::factory()->create([
            'business_account_id' => $account->id,
            'placed_by' => $owner->id,
            'status' => OrderStatus::Paid,
            'subtotal' => $total,
            'total' => $total,
        ]);

        $order->payment?->forceFill(['status' => PaymentStatus::Paid, 'amount' => $total])->save();

        foreach ($lines as $position => [$product, $quantity]) {
            $lineTotal = $product->wholesale_price->multipliedBy($quantity);

            OrderItem::create([
                'order_id' => $order->id,
                'line_number' => $position + 1,
                'product_id' => $product->id,
                'sku' => $product->sku,
                'product_name' => $product->name,
                'quantity' => $quantity,
                'currency_code' => 'BDT',
                'unit_price' => $product->wholesale_price,
                'line_subtotal' => $lineTotal,
                'line_total' => $lineTotal,
                'created_at' => now(),
            ]);
        }

        $this->command->info("Fake order {$order->reference} for {$account->name} (total {$total->toDecimal()}).");
    }

    protected function product(string $bpc, string $name, string $wholesale): Product
    {
        $category = Category::query()->firstOrCreate(['name' => 'Fake clothing'], ['is_active' => true]);

        return Product::query()->firstOrCreate(['sku' => $bpc], [
            'name' => $name,
            'category_id' => $category->id,
            'status' => ProductStatus::Active,
            'currency_code' => 'BDT',
            'base_cost' => Money::zero(Currency::BDT),
            'wholesale_price' => Money::fromDecimal($wholesale, Currency::BDT),
            'wholesale_status' => ProductStatus::WholesaleEnabled,
            'package_scope' => PackageScope::AllPackages,
            'account_scope' => AccountScope::AnyAccount,
        ]);
    }
}
