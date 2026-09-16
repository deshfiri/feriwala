<?php

namespace App\Domain\Website\Enums;

use App\Support\StateMachine\TransitionableState;

/**
 * Where a dedicated website stands: every status §16.4 names (P5-9).
 *
 * Fourteen, and the database holds the column to the same list. The map is
 * deliberately conservative — a move is here only where a website can genuinely
 * go next. Whether a particular move is allowed *now* (a setup charge still
 * owed, a package that has lapsed) belongs to the action making it.
 *
 * Four groups, and the difference matters:
 *
 *   - **Being built** — setup pending, deposit pending, development, API
 *     connection pending. Nothing is served to a customer yet.
 *   - **Live** — active, and the four states that are live but flagged: a low
 *     wallet balance, a grace period, a domain or hosting renewal falling due.
 *     A storefront in any of them still answers, because taking a shop down the
 *     hour a renewal falls due punishes the customer for the partner's lapse
 *     (§24.3).
 *   - **Not serving** — temporarily disabled, package expired, suspended,
 *     maintenance. The credentials stop working; maintenance is the one the
 *     partner chooses.
 *   - **Closed** — the end, and there is no way back out of it.
 */
enum WebsiteStatus: string implements TransitionableState
{
    case SetupPending = 'setup_pending';
    case DepositPending = 'deposit_pending';
    case Development = 'development';
    case ApiConnectionPending = 'api_connection_pending';
    case Active = 'active';
    case LowWalletBalance = 'low_wallet_balance';
    case GracePeriod = 'grace_period';
    case TemporarilyDisabled = 'temporarily_disabled';
    case PackageExpired = 'package_expired';
    case DomainRenewalPending = 'domain_renewal_pending';
    case HostingRenewalPending = 'hosting_renewal_pending';
    case Suspended = 'suspended';
    case Maintenance = 'maintenance';
    case Closed = 'closed';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            // The charges come first: a website is built once it is paid for.
            self::SetupPending => [self::DepositPending, self::Development, self::Closed],
            self::DepositPending => [self::SetupPending, self::Development, self::Closed],

            self::Development => [self::ApiConnectionPending, self::Active, self::Suspended, self::Closed],
            self::ApiConnectionPending => [self::Active, self::Development, self::Suspended, self::Closed],

            self::Active => [
                self::LowWalletBalance, self::GracePeriod, self::DomainRenewalPending,
                self::HostingRenewalPending, self::PackageExpired, self::TemporarilyDisabled,
                self::Maintenance, self::Suspended, self::Closed,
            ],

            // Live but flagged. Each returns to active when the thing that
            // flagged it is put right, or falls further when it is not.
            self::LowWalletBalance => [
                self::Active, self::GracePeriod, self::PackageExpired, self::TemporarilyDisabled,
                self::Maintenance, self::Suspended, self::Closed,
            ],
            self::GracePeriod => [
                self::Active, self::PackageExpired, self::TemporarilyDisabled,
                self::Suspended, self::Closed,
            ],
            self::DomainRenewalPending => [
                self::Active, self::TemporarilyDisabled, self::Maintenance,
                self::Suspended, self::Closed,
            ],
            self::HostingRenewalPending => [
                self::Active, self::TemporarilyDisabled, self::Maintenance,
                self::Suspended, self::Closed,
            ],

            self::PackageExpired => [self::Active, self::GracePeriod, self::TemporarilyDisabled, self::Suspended, self::Closed],
            self::TemporarilyDisabled => [self::Active, self::Maintenance, self::Suspended, self::Closed],
            self::Maintenance => [self::Active, self::TemporarilyDisabled, self::Suspended, self::Closed],
            self::Suspended => [self::Active, self::TemporarilyDisabled, self::Closed],

            self::Closed => [],
        };
    }

    /**
     * Whether the storefront is being served to customers.
     *
     * Read by the API credentials: a website that is not serving answers
     * nothing, whatever key is presented.
     */
    public function isLive(): bool
    {
        return match ($this) {
            self::Active,
            self::LowWalletBalance,
            self::GracePeriod,
            self::DomainRenewalPending,
            self::HostingRenewalPending => true,

            default => false,
        };
    }

    /**
     * Whether the website is still being set up rather than run.
     */
    public function isBeingBuilt(): bool
    {
        return match ($this) {
            self::SetupPending,
            self::DepositPending,
            self::Development,
            self::ApiConnectionPending => true,

            default => false,
        };
    }

    /**
     * Whether this is the end of the website's life.
     *
     * Closed is the only one. A suspended shop can be restored and a disabled
     * one can be put right; a closed one is finished with, and the map gives it
     * nowhere to go.
     */
    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }

    /**
     * Whether catalogue and stock changes are pushed out in this state.
     *
     * A website under maintenance still receives them: the partner is working
     * on it, and it should be current the moment it reopens.
     */
    public function acceptsSync(): bool
    {
        return $this->isLive() || $this === self::Maintenance || $this === self::ApiConnectionPending;
    }

    public function label(): string
    {
        return match ($this) {
            self::SetupPending => 'Setup pending',
            self::DepositPending => 'Deposit pending',
            self::Development => 'In development',
            self::ApiConnectionPending => 'API connection pending',
            self::Active => 'Active',
            self::LowWalletBalance => 'Low wallet balance',
            self::GracePeriod => 'Grace period',
            self::TemporarilyDisabled => 'Temporarily disabled',
            self::PackageExpired => 'Package expired',
            self::DomainRenewalPending => 'Domain renewal pending',
            self::HostingRenewalPending => 'Hosting renewal pending',
            self::Suspended => 'Suspended',
            self::Maintenance => 'Maintenance',
            self::Closed => 'Closed',
        };
    }

    /**
     * Every status value, for a database CHECK or a validation rule.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
