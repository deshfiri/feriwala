<?php

namespace App\Domain\Kyc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A non-file KYC answer — a TIN, a bank account number, a nominee's name.
 *
 * The value is encrypted at rest. These are identity and banking details, and
 * §7.5 requires encryption where appropriate; a database backup or a stray
 * query log should not expose them.
 */
class KycSubmissionField extends Model
{
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['value'];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
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
     * A partially hidden value for display in a list, so a reviewer can tell
     * two entries apart without the full number being on screen (§36 masking).
     */
    public function masked(): string
    {
        $value = (string) $this->value;
        $length = strlen($value);

        if ($length <= 4) {
            return str_repeat('•', $length);
        }

        return str_repeat('•', $length - 4).substr($value, -4);
    }
}
