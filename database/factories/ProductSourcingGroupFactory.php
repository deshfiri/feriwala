<?php

namespace Database\Factories;

use App\Domain\Sourcing\Models\ProductSourcingGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductSourcingGroup>
 */
class ProductSourcingGroupFactory extends Factory
{
    protected $model = ProductSourcingGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'grp-'.Str::lower(Str::random(8)),
            'name_en' => 'Men\'s Regular Pants',
            'name_bn' => 'পুরুষদের রেগুলার প্যান্ট',
            'description' => null,
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
