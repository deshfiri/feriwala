<?php

namespace App\Domain\Notification\Models;

use App\Concerns\HasPublicId;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Notification\Enums\SmsStatus;
use App\Integrations\Sms\Data\SmsMessage;
use App\Models\User;
use App\Support\Localization\Locale;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One text message, and what became of it (§30.2).
 *
 * Named for the record rather than the message so it does not collide with
 * {@see SmsMessage}, which is the thing handed to a provider. This is the
 * durable half: it exists before the provider is called and survives whatever
 * the provider says.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $user_id
 * @property int|null $business_account_id
 * @property string $event
 * @property string $recipient
 * @property string $locale
 * @property string $body
 * @property int $segments
 * @property SmsStatus $status
 * @property string|null $provider
 * @property string|null $provider_reference
 * @property string|null $cost
 * @property string|null $error
 * @property int $attempts
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $failed_at
 * @property string $dedupe_key
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $user
 */
class SmsMessageRecord extends Model
{
    use HasPublicId;

    protected $table = 'sms_messages';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SmsStatus::class,
            'segments' => 'integer',
            'attempts' => 'integer',
            'queued_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }

    /**
     * What a provider actually gets handed.
     */
    public function toProviderMessage(): SmsMessage
    {
        return new SmsMessage(
            to: $this->recipient,
            body: $this->body,
            locale: Locale::tryFrom($this->locale) ?? Locale::English,
            event: $this->event,
            userId: $this->user_id,
        );
    }
}
