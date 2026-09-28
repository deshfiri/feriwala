<?php

namespace App\Domain\Location\Rules;

use App\Domain\Location\Enums\BdLocationType;
use App\Domain\Location\Models\BdLocation;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The field must be an active `bd_locations` row of the expected level, whose
 * parent is the value already submitted for the level above it.
 *
 * One class attached to every level's field —
 * `'division_id' => [new self(BdLocationType::Division)]`,
 * `'district_id' => [new self(BdLocationType::District)]`, and so on — rather
 * than a class per level. Division has no parent to check; every other level
 * reads the parent field's value from the request's own data via
 * {@see DataAwareRule}, never from the database again, so a hierarchy is
 * validated against what the person actually chose, not re-derived.
 */
class ValidBdLocationHierarchy implements DataAwareRule, ValidationRule
{
    /**
     * @var array<string, mixed>
     */
    protected array $data = [];

    public function __construct(protected BdLocationType $type) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $location = BdLocation::query()
            ->ofType($this->type)
            ->where('id', $value)
            ->where('is_active', true)
            ->first();

        if ($location === null) {
            $fail("The selected {$this->type->label()} is not valid.");

            return;
        }

        $parentType = $this->type->parent();

        if ($parentType === null) {
            return;
        }

        $parentField = "{$parentType->value}_id";
        $expectedParentId = $this->data[$parentField] ?? null;

        if ($expectedParentId === null || (int) $location->parent_id !== (int) $expectedParentId) {
            $fail("The selected {$this->type->label()} does not belong to the chosen {$parentType->label()}.");
        }
    }
}
