<?php

namespace Database\Seeders;

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Five approved Suppliers and five active Partners with generated names and
 * contact details, for trying the screens with some volume.
 *
 * Not part of {@see DatabaseSeeder}: run it on purpose, and never against data
 * somebody cares about —
 *
 *     php artisan db:seed --class=FakeDataSeeder
 *
 * Safe to run twice: the logins are fixed (`supplier1@banij.test` …
 * `supplier5@banij.test`, `partner1@banij.test` … `partner5@banij.test`, password
 * `password`), and one that already exists is left as it is.
 */
class FakeDataSeeder extends Seeder
{
    public const COUNT = 5;

    public function run(): void
    {
        for ($n = 1; $n <= self::COUNT; $n++) {
            $this->supplier($n);
            $this->partner($n);
        }

        $this->command->info(sprintf('Fake data: %d Suppliers and %d Partners (password: password).', self::COUNT, self::COUNT));
    }

    protected function supplier(int $n): void
    {
        $email = "supplier{$n}@banij.test";

        if (Supplier::query()->where('email', $email)->exists()) {
            return;
        }

        Supplier::factory()->create([
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }

    protected function partner(int $n): void
    {
        $email = "partner{$n}@banij.test";

        if (User::query()->where('email', $email)->exists()) {
            return;
        }

        $account = BusinessAccount::factory()
            ->onboarding(AccountStatus::Active)
            ->create();

        $account->owner->forceFill([
            'email' => $email,
            'password' => Hash::make('password'),
        ])->save();

        app(OpenWallet::class)->handle($account);
    }
}
