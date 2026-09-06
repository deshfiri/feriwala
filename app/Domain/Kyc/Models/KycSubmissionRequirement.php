<?php

namespace App\Domain\Kyc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one round was opened against (§7.2, §7.3).
 *
 * A **copy** of the document type as it stood when the round began, not a
 * reference to it. Without this an administrator editing a type retroactively
 * changes what a submitted round was judged on: a reviewer reads "passport
 * required" beside a submission made when it was optional, and an applicant who
 * complied is suddenly shown as incomplete.
 *
 * The type id is kept so uploads can be joined back, and so an archived type
 * still resolves — but every field a person reads comes from here.
 *
 * @property string $key
 * @property string $name
 * @property string|null $instructions
 * @property bool $is_required
 * @property bool $requires_file
 * @property bool $requires_value
 * @property string|null $value_label
 * @property array<int, string> $accepted_mime_types
 * @property int $max_size_kb
 * @property int $sort_order
 */
class KycSubmissionRequirement extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepted_mime_types' => 'array',
            'is_required' => 'boolean',
            'requires_file' => 'boolean',
            'requires_value' => 'boolean',
            'max_size_kb' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<KycSubmission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(KycSubmission::class, 'kyc_submission_id');
    }

    /**
     * @return BelongsTo<KycDocumentType, $this>
     */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(KycDocumentType::class, 'kyc_document_type_id');
    }

    /**
     * Whether an uploaded file is acceptable — judged against the rules this
     * round was given, not against the type as it is today.
     */
    public function accepts(string $mimeType, int $sizeBytes): bool
    {
        return in_array($mimeType, $this->accepted_mime_types, true)
            && $sizeBytes <= $this->max_size_kb * 1024;
    }

    /**
     * Build a snapshot row from a live type.
     *
     * `$isRequired` is passed in because the scope may override it (§7.2), and
     * the round has to record what **this** applicant was actually asked for.
     *
     * @return array<string, mixed>
     */
    public static function snapshotOf(KycDocumentType $type, bool $isRequired): array
    {
        return [
            'kyc_document_type_id' => $type->id,
            'key' => $type->key,
            'name' => $type->name,
            'instructions' => $type->instructions,
            'is_required' => $isRequired,
            'requires_file' => $type->requires_file,
            'requires_value' => $type->requires_value,
            'value_label' => $type->value_label,
            'accepted_mime_types' => $type->accepted_mime_types,
            'max_size_kb' => $type->max_size_kb,
            'sort_order' => $type->sort_order,
        ];
    }
}
