<?php

namespace Database\Factories;

use App\Domain\Website\Enums\WebsiteServiceStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteHosting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A hosting term bought for a website.
 *
 * @extends Factory<WebsiteHosting>
 */
class WebsiteHostingFactory extends Factory
{
    protected $model = WebsiteHosting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'website_id' => Website::factory(),
            'plan' => 'Storefront standard',
            'provider' => 'Internal',
            'status' => WebsiteServiceStatus::Active,
            'currency_code' => 'BDT',
            'fee_minor' => 200000,
            'started_at' => now()->subMonths(6),
            'expires_at' => now()->addMonths(6),
        ];
    }

    public function expiringIn(int $days): self
    {
        return $this->state(fn () => ['expires_at' => now()->addDays($days)]);
    }
}
