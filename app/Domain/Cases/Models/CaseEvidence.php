<?php

namespace App\Domain\Cases\Models;

use App\Concerns\HasPublicId;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Cases\Enums\EvidencePurpose;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Storage\Models\StoredFile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One file of evidence on a return, exchange or damage case (D35).
 *
 * The file itself is a private {@see StoredFile}; this says whose it is, who put
 * it there and what for. A Partner's begins as a **draft**, belonging to the
 * order and the uploader alone and removable; submitting the request makes it
 * part of the case, and from then on it is history — the database refuses to
 * delete it or move it. Staff add theirs to a case that already exists.
 *
 * Never carries a storage path to anything outside the server: it is served only
 * through {@see CaseEvidencePolicy}'s authorization, by a signed route.
 *
 * @property int $id
 * @property string $public_id
 * @property int $stored_file_id
 * @property int $business_account_id
 * @property int $order_id
 * @property int|null $order_return_id
 * @property int|null $order_damage_id
 * @property string $uploader_type
 * @property int $uploader_id
 * @property EvidencePurpose $purpose
 * @property string $display_name
 * @property string|null $note
 * @property string $status
 * @property CarbonImmutable $uploaded_at
 * @property CarbonImmutable|null $submitted_at
 * @property-read StoredFile $file
 * @property-read BusinessAccount $businessAccount
 * @property-read Order $order
 * @property-read OrderReturn|null $orderReturn
 * @property-read OrderDamage|null $orderDamage
 * @property-read User $uploader
 */
class CaseEvidence extends Model
{
    use HasPublicId;

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const UPLOADED_BY_PARTNER = 'partner';

    public const UPLOADED_BY_STAFF = 'staff';

    protected $table = 'case_evidence';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::deleting(function (CaseEvidence $evidence): void {
            if (! $evidence->isDraft()) {
                throw new RuntimeException('Submitted evidence is never deleted.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => EvidencePurpose::class,
            'uploaded_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<OrderReturn, $this>
     */
    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class);
    }

    /**
     * @return BelongsTo<OrderDamage, $this>
     */
    public function orderDamage(): BelongsTo
    {
        return $this->belongsTo(OrderDamage::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }
}
