<?php

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Supplier\Models\Supplier;
use Database\Seeders\FakeOrderSeeder;

it('seeds a paid order with two lines for Partner 1, and the fake Partners and Suppliers it needs', function () {
    $this->seed(FakeOrderSeeder::class);

    $order = Order::query()->sole();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->businessAccount->owner->email)->toBe('partner1@banij.test')
        ->and(OrderItem::query()->where('order_id', $order->id)->count())->toBe(2)
        // 4 x 1,500 + 2 x 1,100
        ->and($order->total->toDecimal())->toBe('8200.00')
        ->and(Supplier::query()->count())->toBe(5);
});
