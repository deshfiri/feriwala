<?php

namespace App\Domain\Supplier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A typed (non-file) Supplier KYC answer -- a TIN, a licence number. Encrypted
 * at rest, like `App\Domain\Kyc\Models\KycSubmissionField`.
 *
 * @property string $key
 * @property string $value
 */
class SupplierKycField extends Model
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
     * @return BelongsTo<SupplierKycSubmission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(SupplierKycSubmission::class, 'supplier_kyc_submission_id');
    }

    /**
     * All but the last four characters hidden, so the form can show that a
     * value is on file without echoing it back in full.
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
