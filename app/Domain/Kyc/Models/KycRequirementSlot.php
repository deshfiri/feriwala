<?php

namespace App\Domain\Kyc\Models;

use App\Concerns\HasPublicId;
use App\Domain\Kyc\Enums\KycEvidenceType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of evidence a requirement is made of: a file, a typed value, or a
 * declaration (D30).
 *
 * @property string $key
 * @property string $label
 * @property string|null $label_bn
 * @property string|null $instructions
 * @property string|null $instructions_bn
 * @property bool $is_required
 * @property KycEvidenceType $evidence_type
 * @property array<int, string>|null $accepted_mime_types
 * @property int|null $max_size_kb
 * @property string|null $value_label
 * @property string|null $value_label_bn
 * @property bool $is_sensitive
 * @property int $sort_order
 * @property CarbonImmutable|null $archived_at
 */
class KycRequirementSlot extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'evidence_type' => KycEvidenceType::class,
            'accepted_mime_types' => 'array',
            'is_required' => 'boolean',
            'is_sensitive' => 'boolean',
            'max_size_kb' => 'integer',
            'sort_order' => 'integer',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<KycDocumentType, $this>
     */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(KycDocumentType::class, 'kyc_document_type_id');
    }

    /**
     * @param  Builder<KycRequirementSlot>  $query
     * @return Builder<KycRequirementSlot>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The slots a requirement had before slots existed, read from its own
     * columns: one file slot if it asked for a file, one value slot if it asked
     * for a typed value. The same mapping the migration applied, so a type made
     * by an older path (a seeder, a test) reads exactly as a migrated one does.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function derivedFor(KycDocumentType $type): array
    {
        $slots = [];

        if ($type->requires_file) {
            $mimes = $type->accepted_mime_types ?? [];
            $imagesOnly = $mimes !== [] && collect($mimes)->every(fn ($mime) => str_starts_with((string) $mime, 'image/'));

            $slots[] = [
                'key' => 'file',
                'label' => $type->name,
                'label_bn' => $type->name_bn,
                'instructions' => $type->instructions,
                'instructions_bn' => $type->instructions_bn,
                'required' => true,
                'evidence' => $imagesOnly ? 'image' : ($mimes === ['application/pdf'] ? 'pdf' : 'file'),
                'mimes' => $mimes,
                'max_kb' => $type->max_size_kb,
                'value_label' => null,
                'value_label_bn' => null,
                'sensitive' => false,
                'sort' => count($slots),
            ];
        }

        if ($type->requires_value) {
            $slots[] = [
                'key' => 'value',
                'label' => $type->value_label ?? $type->name,
                'label_bn' => null,
                'instructions' => null,
                'instructions_bn' => null,
                'required' => true,
                'evidence' => 'value',
                'mimes' => [],
                'max_kb' => null,
                'value_label' => $type->value_label,
                'value_label_bn' => null,
                'sensitive' => true,
                'sort' => count($slots),
            ];
        }

        return $slots;
    }
}
