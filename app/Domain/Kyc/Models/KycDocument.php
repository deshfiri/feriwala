<?php

namespace App\Domain\Kyc\Models;

use App\Concerns\HasPublicId;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded KYC file (§7.5).
 *
 * The model deliberately exposes **no** method that returns a public URL. §7.5
 * requires these never be reachable without authentication and authorisation,
 * and the surest way to honour that is to give the codebase no way to ask for a
 * public link — a `getUrl()` helper would eventually be called from a view.
 *
 * Reading a document goes through the controller that checks permission and
 * writes an access record.
 *
 * @property string $public_id
 * @property string $disk
 * @property string $path
 * @property int|null $kyc_document_type_id null for the live selfie, which has no catalogue entry
 * @property string|null $slot_key
 * @property CarbonInterface $created_at
 * @property-read KycDocumentType|null $documentType
 */
class KycDocument extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * Hidden from every array and JSON representation.
     *
     * §7.5 forbids these appearing in public exports. Leaking the storage path
     * would let anyone with filesystem or bucket access find the file directly,
     * bypassing the permission check entirely.
     *
     * @var list<string>
     */
    protected $hidden = ['disk', 'path', 'checksum'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'is_encrypted' => 'boolean',
            'captured_live' => 'boolean',
            'captured_at' => 'immutable_datetime',
            'capture_meta' => 'array',
            'superseded_at' => 'immutable_datetime',
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
     * Every recorded view and download (§7.5).
     *
     * @return HasMany<KycDocumentAccess, $this>
     */
    public function accesses(): HasMany
    {
        return $this->hasMany(KycDocumentAccess::class)->latest('created_at');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }
}
