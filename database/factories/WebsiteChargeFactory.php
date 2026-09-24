<?php

namespace Database\Factories;

use App\Domain\Website\Enums\WebsiteChargeStatus;
use App\Domain\Website\Enums\WebsiteChargeType;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCharge;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * One amount a website owes.
 *
 * @extends Factory<WebsiteCharge>
 */
class WebsiteChargeFactory extends Factory
{
    protected $model = WebsiteCharge::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'website_id' => Website::factory(),
            'business_account_id' => fn (array $attributes) => Website::query()
                ->whereKey($attributes['website_id'])
                ->value('business_account_id'),
            'type' => WebsiteChargeType::Setup,
            'status' => WebsiteChargeStatus::Due,
            'currency_code' => 'BDT',
            'amount' => Money::fromDecimal('5000.00'),
            'due_at' => now(),
        ];
    }

    public function ofType(WebsiteChargeType $type): self
    {
        return $this->state(fn () => ['type' => $type]);
    }
}
