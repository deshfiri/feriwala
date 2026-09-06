<?php

namespace Database\Factories;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Tax\Models\TaxExemption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxExemption>
 */
class TaxExemptionFactory extends Factory
{
    protected $model = TaxExemption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_account_id' => BusinessAccount::factory(),
            'reason' => 'Registered export house',
            'certificate_reference' => 'EX-'.fake()->unique()->numerify('######'),
            'effective_from' => now()->subMonth(),
            'effective_until' => null,
        ];
    }

    /** Its window has closed. */
    public function expired(): static
    {
        return $this->state(fn () => [
            'effective_from' => now()->subYear(),
            'effective_until' => now()->subDay(),
        ]);
    }

    /** Withdrawn before its window closed. */
    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }

    /** Granted, but not in force yet. */
    public function future(): static
    {
        return $this->state(fn () => ['effective_from' => now()->addWeek()]);
    }
}
