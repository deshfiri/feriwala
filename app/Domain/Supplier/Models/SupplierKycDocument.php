<?php

namespace App\Domain\Supplier\Models;

use App\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One uploaded Supplier KYC file (D25, mirrors `App\Domain\Kyc\Models\KycDocument`).
 *
 * Deliberately exposes **no** method returning a public URL — reading one goes
 * through `SupplierKycDocumentStore`, which checks permission first.
 *
 * @property string $public_id
 * @property string $disk
 * @property string $path
 */
class SupplierKycDocument extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @var list<string>
     */
    protected $hidden = ['disk', 'path', 'checksum'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'is_encrypted' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SupplierKycSubmission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(SupplierKycSubmission::class, 'supplier_kyc_submission_id');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }
}
