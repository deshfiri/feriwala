<?php

namespace Database\Factories;

use App\Domain\Kyc\Models\KycDocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KycDocumentType>
 */
class KycDocumentTypeFactory extends Factory
{
    protected $model = KycDocumentType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = 'doc_'.Str::lower(Str::random(8));

        return [
            'key' => $key,
            'name' => 'National ID',
            'instructions' => 'Both sides, in colour.',
            'is_required' => true,
            'is_active' => true,
            'archived_at' => null,
            'requires_file' => true,
            'requires_value' => false,
            'value_label' => null,
            'accepted_mime_types' => ['image/jpeg', 'image/png', 'application/pdf'],
            'max_size_kb' => 5120,
            'sort_order' => 0,
        ];
    }

    public function key(string $key): static
    {
        return $this->state(fn () => ['key' => $key]);
    }

    public function optional(): static
    {
        return $this->state(fn () => ['is_required' => false]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function archived(): static
    {
        return $this->state(fn () => [
            'archived_at' => now(),
            'is_active' => false,
        ]);
    }

    /** Asks for a typed value rather than a file — a TIN, a licence number. */
    public function valueOnly(string $label = 'Number'): static
    {
        return $this->state(fn () => [
            'requires_file' => false,
            'requires_value' => true,
            'value_label' => $label,
        ]);
    }

    /**
     * Limited to a package, a country, or both (§7.2).
     */
    /**
     * @param  string|null  $package  a package **public id**, not a slug
     */
    public function scopedTo(?string $package = null, ?string $country = null, ?bool $isRequired = null): static
    {
        return $this->afterCreating(function (KycDocumentType $type) use ($package, $country, $isRequired) {
            $type->scopes()->create([
                'package_public_id' => $package,
                'country_code' => $country === null ? null : mb_strtoupper($country),
                'is_required' => $isRequired,
            ]);
        });
    }
}
