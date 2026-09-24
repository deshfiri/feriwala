<?php

namespace App\Domain\Website\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Concerns\HasStateMachine;
use App\Concerns\RecordsStatusHistory;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Website\Enums\WebsiteConnectionHealth;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Enums\WebsiteTheme;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Factories\WebsiteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One dedicated partner storefront (§16, P5-8).
 *
 * Owned by exactly one business account, which is what makes it the tenancy
 * boundary the API contract relies on (D11 §3.5): a credential belongs to a
 * website, a website belongs to an account, and there is no third way to reach
 * either. The owning account cannot be changed — the database refuses it.
 *
 * Status moves only through `transitionTo()` along {@see WebsiteStatus}, and
 * every move writes its history row.
 *
 * @property int $id
 * @property string $public_id
 * @property int $business_account_id
 * @property int|null $user_package_id
 * @property string $name
 * @property string $slug
 * @property string $subdomain
 * @property string|null $domain
 * @property WebsiteStatus $status
 * @property string $currency_code
 * @property Money $setup_fee
 * @property Money $domain_fee
 * @property Money $hosting_fee
 * @property Money $required_deposit
 * @property Money $minimum_balance
 * @property string|null $tagline
 * @property string|null $about
 * @property string|null $logo_path
 * @property string|null $banner_path
 * @property string $primary_color
 * @property string $secondary_color
 * @property WebsiteTheme $theme
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $contact_address
 * @property array<string, mixed> $payment_config
 * @property array<string, mixed> $shipping_config
 * @property CarbonImmutable|null $api_connected_at
 * @property CarbonImmutable|null $webhook_connected_at
 * @property CarbonImmutable|null $last_synced_at
 * @property WebsiteConnectionHealth $connection_health
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $grace_ends_at
 * @property CarbonImmutable|null $suspended_at
 * @property string|null $suspension_reason
 * @property string|null $maintenance_message
 * @property CarbonImmutable|null $closed_at
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read BusinessAccount $businessAccount
 * @property-read UserPackage|null $userPackage
 * @property-read User|null $createdBy
 * @property-read Collection<int, WebsiteStatusChange> $statusHistory
 * @property-read Collection<int, WebsiteDomain> $domains
 * @property-read Collection<int, WebsiteHosting> $hostings
 * @property-read Collection<int, WebsiteCharge> $charges
 */
class Website extends Model
{
    /** @use HasFactory<WebsiteFactory> */
    use HasFactory, HasPublicId, HasStateMachine, RecordsStatusHistory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WebsiteStatus::class,
            'theme' => WebsiteTheme::class,
            'connection_health' => WebsiteConnectionHealth::class,
            'setup_fee' => MoneyCast::class,
            'domain_fee' => MoneyCast::class,
            'hosting_fee' => MoneyCast::class,
            'required_deposit' => MoneyCast::class,
            'minimum_balance' => MoneyCast::class,
            'payment_config' => 'array',
            'shipping_config' => 'array',
            'api_connected_at' => 'immutable_datetime',
            'webhook_connected_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'grace_ends_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }

    /**
     * @return BelongsTo<UserPackage, $this>
     */
    public function userPackage(): BelongsTo
    {
        return $this->belongsTo(UserPackage::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<WebsiteStatusChange, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(WebsiteStatusChange::class)->orderBy('id');
    }

    /**
     * @return HasMany<WebsiteDomain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(WebsiteDomain::class);
    }

    /**
     * @return HasMany<WebsiteHosting, $this>
     */
    public function hostings(): HasMany
    {
        return $this->hasMany(WebsiteHosting::class);
    }

    /**
     * @return HasMany<WebsiteCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(WebsiteCharge::class);
    }

    /**
     * Domain models live deeper than the factory naming convention expects, so
     * the binding is stated rather than guessed.
     */
    protected static function newFactory(): WebsiteFactory
    {
        return WebsiteFactory::new();
    }

    /**
     * The address customers reach this storefront at.
     *
     * The partner's own domain once there is one, and the subdomain Feriwala
     * always provides until then — so a website in development still has an
     * address to show, rather than a blank where one will eventually be.
     */
    public function host(): string
    {
        return $this->domain ?? $this->subdomain.'.'.config('website.storefront_domain');
    }

    /**
     * Whether the storefront is being served right now.
     */
    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    /**
     * Websites belonging to one account.
     *
     * @param  Builder<Website>  $query
     * @return Builder<Website>
     */
    public function scopeForAccount(Builder $query, BusinessAccount $account): Builder
    {
        return $query->where('business_account_id', $account->id);
    }

    /**
     * Websites that are not finished with.
     *
     * @param  Builder<Website>  $query
     * @return Builder<Website>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', '!=', WebsiteStatus::Closed->value);
    }
}
