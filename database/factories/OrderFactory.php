<?php

namespace Database\Factories;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An ERP wholesale order waiting for payment, with the payment it belongs to.
 *
 * For tests that need an order to exist. An order a person actually places goes
 * through the wholesale checkout, which reserves stock and prices every line;
 * this makes none of that happen.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $address = [
            'contact_name' => fake()->name(),
            'contact_mobile' => '01712345678',
            'line_1' => fake()->streetAddress(),
            'line_2' => null,
            'area' => 'Mirpur',
            'city' => 'Dhaka',
            'district' => 'Dhaka',
            'postcode' => '1216',
            'country' => 'BD',
        ];

        return [
            'source' => OrderSource::ErpWholesale,
            'status' => OrderStatus::PaymentPending,
            'business_account_id' => BusinessAccount::factory(),
            'placed_by' => fn (array $attributes) => BusinessAccount::query()->whereKey($attributes['business_account_id'])->value('owner_id'),
            'payment_id' => fn (array $attributes) => Payment::create([
                'business_account_id' => $attributes['business_account_id'],
                'purpose' => PaymentPurpose::WholesaleOrder,
                'status' => PaymentStatus::Draft,
                'currency_code' => 'BDT',
                'amount' => Money::fromDecimal('1000.00'),
            ])->id,
            'idempotency_key' => fn () => 'wholesale-order:'.Str::ulid(),
            'customer' => fn (array $attributes) => [
                'business_name' => BusinessAccount::query()->whereKey($attributes['business_account_id'])->value('name'),
                'contact_name' => $address['contact_name'],
                'email' => fake()->safeEmail(),
                'mobile' => $address['contact_mobile'],
            ],
            'billing_address' => $address,
            'shipping_address' => $address,
            'currency_code' => 'BDT',
            'subtotal' => Money::fromDecimal('1000.00'),
            'total' => Money::fromDecimal('1000.00'),
            'placed_at' => now(),
        ];
    }
}
