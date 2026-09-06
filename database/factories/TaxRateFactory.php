<?php

namespace Database\Factories;

use App\Domain\Tax\Models\TaxRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxRate>
 */
class TaxRateFactory extends Factory
{
    protected $model = TaxRate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'vat-standard',
            'name' => 'VAT',

            // 15%, as a test fixture only. D19 is explicit that the statutory
            // rate is confirmed with an accountant before launch, so no
            // migration or seeder creates one — a test may.
            'rate_basis_points' => 1500,

            'effective_from' => now()->subYear(),
            'effective_until' => null,
            'is_active' => true,
        ];
    }

    public function percent(float $percent): static
    {
        return $this->state(fn () => [
            'rate_basis_points' => (int) round($percent * TaxRate::BASIS_POINTS_PER_PERCENT),
        ]);
    }

    public function code(string $code): static
    {
        return $this->state(fn () => ['code' => $code]);
    }

    public function zeroRated(): static
    {
        return $this->state(fn () => [
            'code' => 'vat-zero',
            'name' => 'Zero rated',
            'rate_basis_points' => 0,
        ]);
    }

    /**
     * A superseded version: it was in force, and no longer is.
     */
    public function endedAt(\DateTimeInterface $until): static
    {
        return $this->state(fn () => ['effective_until' => $until]);
    }

    public function startingAt(\DateTimeInterface $from): static
    {
        return $this->state(fn () => ['effective_from' => $from]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
