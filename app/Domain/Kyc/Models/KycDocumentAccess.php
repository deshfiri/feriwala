<?php

namespace App\Domain\Kyc\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded view or download of a KYC document (§7.5).
 *
 * Append-only. The value of this table is that it can be trusted during an
 * investigation into who looked at someone's identity documents, which requires
 * that nobody can quietly remove their own row.
 */
class KycDocumentAccess extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \RuntimeException(
            'KYC document access records are append-only (requirements.txt §7.5).'
        ));

        static::deleting(fn () => throw new \RuntimeException(
            'KYC document access records are append-only and cannot be deleted.'
        ));
    }

    /**
     * @return BelongsTo<KycDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(KycDocument::class, 'kyc_document_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function accessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accessed_by');
    }
}
