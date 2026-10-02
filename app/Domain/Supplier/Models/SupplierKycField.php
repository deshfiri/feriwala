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
}
