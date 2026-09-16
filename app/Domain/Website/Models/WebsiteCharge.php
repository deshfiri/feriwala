<?php

namespace App\Domain\Website\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Website\Enums\WebsiteChargeStatus;
use App\Domain\Website\Enums\WebsiteChargeType;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Factories\WebsiteChargeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One amount a website owes, and what settled it (§16.2, §24, P5-10).
 *
 * Raised **due** and settled by a wallet debit, never by writing `paid` on its
 * own: the transaction it points at is the evidence, and the database holds a
 * paid charge to having one. The amount, the type and the account are locked
 * once written — a charge that can be re-priced after the fact is not a record
 * of what somebody agreed to pay.
 *
 * @property int $id
 * @property string $public_id
 * @property int $website_id
 * @property int $business_account_id
 * @property WebsiteChargeType $type
 * @property WebsiteChargeStatus $status
 * @property string $currency_code
 * @property Money $amount_minor
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property int|null $website_domain_id
 * @property int|null $website_hosting_id
 * @property int|null $wallet_transaction_id
 * @property CarbonImmutable $due_at
 * @property CarbonImmutable|null $paid_at
 * @property string|null $reason
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Website $website
 * @property-read BusinessAccount $businessAccount
 * @property-read WalletTransaction|null $walletTransaction
 * @property-read WebsiteDomain|null $websiteDomain
 * @property-read WebsiteHosting|null $websiteHosting
 * @property-read User|null $createdBy
 */
class WebsiteCharge extends Model
{
    /** @use HasFactory<WebsiteChargeFactory> */
    use HasFactory, HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WebsiteChargeType::class,
            'status' => WebsiteChargeStatus::class,
            'amount_minor' => MoneyCast::class,
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }

    /**
     * @return BelongsTo<WalletTransaction, $this>
     */
    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    /**
     * @return BelongsTo<WebsiteDomain, $this>
     */
    public function websiteDomain(): BelongsTo
    {
        return $this->belongsTo(WebsiteDomain::class);
    }

    /**
     * @return BelongsTo<WebsiteHosting, $this>
     */
    public function websiteHosting(): BelongsTo
    {
        return $this->belongsTo(WebsiteHosting::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Domain models live deeper than the factory naming convention expects, so
     * the binding is stated rather than guessed.
     */
    protected static function newFactory(): WebsiteChargeFactory
    {
        return WebsiteChargeFactory::new();
    }

    /**
     * The key that makes paying this charge happen once, however many times the
     * button is pressed or the job is retried.
     */
    public function settlementKey(): string
    {
        return 'website-charge:'.$this->public_id;
    }

    /**
     * Charges still owed.
     *
     * @param  Builder<WebsiteCharge>  $query
     * @return Builder<WebsiteCharge>
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('status', WebsiteChargeStatus::Due->value);
    }
}
