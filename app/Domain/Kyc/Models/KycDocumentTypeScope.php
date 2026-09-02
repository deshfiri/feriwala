<?php

namespace App\Domain\Kyc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Limits a document type to a package or a country (§7.2).
 */
class KycDocumentTypeScope extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<KycDocumentType, $this>
     */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(KycDocumentType::class, 'kyc_document_type_id');
    }
}
