<?php

namespace Database\Factories;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Website\Actions\RequestWebsite;
use App\Domain\Website\Enums\WebsiteConnectionHealth;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A dedicated website, for tests that need one to exist.
 *
 * A website a partner actually asks for goes through {@see RequestWebsite},
 * which checks the package, counts what is already open and raises the charges.
 * This makes none of that happen.
 *
 * @extends Factory<Website>
 */
class WebsiteFactory extends Factory
{
    protected $model = Website::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();
        $slug = Str::slug($name).'-'.Str::lower(Str::random(5));

        return [
            'business_account_id' => BusinessAccount::factory(),
            'name' => $name,
            'slug' => $slug,
            'subdomain' => Str::lower(Str::random(10)),
            'status' => WebsiteStatus::SetupPending,
            'currency_code' => 'BDT',
            'setup_fee_minor' => 500000,
            'domain_fee_minor' => 150000,
            'hosting_fee_minor' => 200000,
            'connection_health' => WebsiteConnectionHealth::Unknown,
        ];
    }

    /**
     * Live, and serving customers.
     */
    public function active(): self
    {
        return $this->state(fn () => [
            'status' => WebsiteStatus::Active,
            'activated_at' => now(),
            'connection_health' => WebsiteConnectionHealth::Healthy,
            'api_connected_at' => now(),
        ]);
    }

    public function inDevelopment(): self
    {
        return $this->state(fn () => ['status' => WebsiteStatus::Development]);
    }

    public function suspended(): self
    {
        return $this->state(fn () => [
            'status' => WebsiteStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => 'Suspended for testing.',
        ]);
    }

    public function forAccount(BusinessAccount $account): self
    {
        return $this->state(fn () => ['business_account_id' => $account->id]);
    }
}
