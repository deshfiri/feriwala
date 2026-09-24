<?php

namespace Database\Factories;

use App\Domain\Website\Enums\WebsiteServiceStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteDomain;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A domain registered for a website.
 *
 * @extends Factory<WebsiteDomain>
 */
class WebsiteDomainFactory extends Factory
{
    protected $model = WebsiteDomain::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'website_id' => Website::factory(),
            'domain' => Str::lower(Str::random(10)).'.example',
            'status' => WebsiteServiceStatus::Active,
            'currency_code' => 'BDT',
            'fee' => Money::fromDecimal('1500.00'),
            'registered_at' => now()->subMonths(6),
            'expires_at' => now()->addMonths(6),
        ];
    }

    /**
     * Running out in this many days.
     */
    public function expiringIn(int $days): self
    {
        return $this->state(fn () => ['expires_at' => now()->addDays($days)]);
    }
}
