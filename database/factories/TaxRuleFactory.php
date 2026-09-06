<?php

namespace Database\Factories;

use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Tax\Enums\TaxMode;
use App\Domain\Tax\Enums\TaxScope;
use App\Domain\Tax\Models\TaxRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxRule>
 */
class TaxRuleFactory extends Factory
{
    protected $model = TaxRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scope' => TaxScope::Everything,
            'scope_value' => null,
            'tax_code' => 'vat-standard',
            'mode' => TaxMode::Exclusive,
            'priority' => 0,
            'effective_from' => now()->subYear(),
            'effective_until' => null,
            'is_active' => true,
        ];
    }

    public function forFee(AllocationType $type): static
    {
        return $this->state(fn () => [
            'scope' => TaxScope::Fee,
            'scope_value' => $type->value,
        ]);
    }

    public function forCategory(string $category): static
    {
        return $this->state(fn () => [
            'scope' => TaxScope::Category,
            'scope_value' => $category,
        ]);
    }

    public function forProduct(string $product): static
    {
        return $this->state(fn () => [
            'scope' => TaxScope::Product,
            'scope_value' => $product,
        ]);
    }

    public function usingCode(string $code): static
    {
        return $this->state(fn () => ['tax_code' => $code]);
    }

    public function inclusive(): static
    {
        return $this->state(fn () => ['mode' => TaxMode::Inclusive]);
    }

    public function priority(int $priority): static
    {
        return $this->state(fn () => ['priority' => $priority]);
    }

    public function startingAt(\DateTimeInterface $from): static
    {
        return $this->state(fn () => ['effective_from' => $from]);
    }

    public function endedAt(\DateTimeInterface $until): static
    {
        return $this->state(fn () => ['effective_until' => $until]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
