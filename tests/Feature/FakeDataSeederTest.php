<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Wallet\Models\Wallet;
use Database\Seeders\FakeDataSeeder;

it('seeds five approved Suppliers and five active Partners, once however often it runs', function () {
    $this->seed(FakeDataSeeder::class);
    $this->seed(FakeDataSeeder::class);

    expect(Supplier::query()->count())->toBe(5)
        ->and(Supplier::query()->where('status', SupplierStatus::Approved)->count())->toBe(5)
        ->and(BusinessAccount::query()->where('status', AccountStatus::Active)->count())->toBe(5)
        ->and(Wallet::query()->count())->toBe(5)
        ->and(Supplier::query()->where('email', 'supplier3@banij.test')->exists())->toBeTrue()
        ->and(BusinessAccount::query()->whereHas('owner', fn ($q) => $q->where('email', 'partner3@banij.test'))->exists())->toBeTrue();
});
