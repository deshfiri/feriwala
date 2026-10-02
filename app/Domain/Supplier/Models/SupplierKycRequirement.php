<?php

namespace App\Domain\Supplier\Models;

use App\Domain\Kyc\Models\KycDocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one Supplier KYC round was opened against: a copy of the document type
 * as it stood then, so a later edit cannot change what the round is judged on
 * (mirrors `App\Domain\Kyc\Models\KycSubmissionRequirement`).
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
class SupplierKycRequirement extends Model
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
     * @return BelongsTo<SupplierKycSubmission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(SupplierKycSubmission::class, 'supplier_kyc_submission_id');
    }

    /**
     * @return BelongsTo<KycDocumentType, $this>
     */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(KycDocumentType::class, 'kyc_document_type_id');
    }

    public function accepts(string $mimeType, int $sizeBytes): bool
    {
        return in_array($mimeType, $this->accepted_mime_types, true)
            && $sizeBytes <= $this->max_size_kb * 1024;
    }
}
