<?php

namespace App\Domain\Account\Queries;

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Wallet\Models\WalletRestriction;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every Client/Partner business account, for the staff operations list.
 *
 * "Partner" is not a second kind of account — it is an existing business
 * account trading through wholesale, dropshipping or both, which is why this
 * reads `business_accounts` and nothing else. Supplier accounts live in their
 * own domain and never appear here.
 *
 * The builder is deliberately returned rather than a paginated result: the
 * controller decides page size and shape, exactly as
 * {@see PendingActivationQuery} already works, and this stays a query object
 * rather than half a controller.
 *
 * **Nothing here selects a KYC document, a payout detail, a secret or a
 * password column.** The list is a directory; the dossier is where detail
 * lives, behind its own ability.
 */
class AccountDirectory
{
    /**
     * Statuses that mean "this account cannot currently trade because someone
     * stopped it", as opposed to "it has not finished joining yet".
     */
    public const HALTED = [
        AccountStatus::Suspended,
        AccountStatus::TemporarilyDisabled,
        AccountStatus::TemporarilyRestricted,
    ];

    /** Still working through onboarding. */
    public const ONBOARDING = [
        AccountStatus::Registered,
        AccountStatus::MobileVerificationPending,
        AccountStatus::EmailVerificationPending,
        AccountStatus::KycPending,
        AccountStatus::KycSubmitted,
        AccountStatus::KycUnderReview,
        AccountStatus::KycResubmissionRequired,
        AccountStatus::KycApproved,
        AccountStatus::KycRejected,
        AccountStatus::PackageSelectionPending,
        AccountStatus::PaymentPending,
        AccountStatus::PaymentVerificationPending,
        AccountStatus::ApprovalPending,
    ];

    /**
     * Trading, in one form or another. `Active` plus the operating states that
     * still permit work — an account with a renewal due is live, not gone.
     */
    public const TRADING = [
        AccountStatus::Active,
        AccountStatus::PackageRenewalDue,
        AccountStatus::LowWalletBalance,
        AccountStatus::WalletTopupRequired,
    ];

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<BusinessAccount>
     */
    public function builder(array $filters = []): Builder
    {
        return BusinessAccount::query()
            ->with([
                'owner:id,name,email,mobile,country,email_verified_at,mobile_verified_at',
                'currentPackage:id,business_account_id,package_id,status,expires_at',
                'currentPackage.package:id,name',
            ])
            ->when($this->text($filters, 'search'), $this->searchBy(...))
            ->when($this->text($filters, 'state'), $this->stateIs(...))
            ->when($this->text($filters, 'status'), fn (Builder $query, string $status) => $query
                ->where('status', $status))
            ->when($this->text($filters, 'kyc_status'), $this->kycStatusIs(...))
            ->when($this->text($filters, 'package'), fn (Builder $query, string $package) => $query
                ->whereHas('currentPackage.package', fn ($p) => $p->where('public_id', $package)))
            ->when($this->text($filters, 'package_status'), fn (Builder $query, string $status) => $query
                ->whereHas('currentPackage', fn ($p) => $p->where('status', $status)))
            ->when($this->text($filters, 'facility'), $this->facilityIs(...))
            // `BusinessAccount` has no wallet relation; the restriction itself
            // carries the account, so it is read from there directly.
            ->when($this->text($filters, 'wallet_restriction') === 'restricted', fn (Builder $query) => $query
                ->whereIn('business_accounts.id', WalletRestriction::query()
                    ->whereNull('lifted_at')
                    ->select('business_account_id')))
            ->when($this->text($filters, 'kyc_reverification'), $this->reverificationIs(...))
            ->when($this->text($filters, 'registered_from'), fn (Builder $query, string $date) => $query
                ->whereDate('created_at', '>=', $date))
            ->when($this->text($filters, 'registered_to'), fn (Builder $query, string $date) => $query
                ->whereDate('created_at', '<=', $date))
            ->when($this->text($filters, 'activated_from'), fn (Builder $query, string $date) => $query
                ->whereDate('activated_at', '>=', $date))
            ->when($this->text($filters, 'activated_to'), fn (Builder $query, string $date) => $query
                ->whereDate('activated_at', '<=', $date));
    }

    /**
     * The counts above the table.
     *
     * Read in one pass rather than one query per tile: a staff list that
     * fires eight aggregates on every page load is a list nobody filters.
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        /** @var array<string, int> $byStatus */
        $byStatus = BusinessAccount::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $sum = fn (array $statuses) => array_sum(array_map(
            fn (AccountStatus $status) => $byStatus[$status->value] ?? 0,
            $statuses,
        ));

        return [
            'total' => array_sum($byStatus),
            'kyc_pending' => $sum([
                AccountStatus::KycPending,
                AccountStatus::KycSubmitted,
                AccountStatus::KycUnderReview,
                AccountStatus::KycResubmissionRequired,
            ]),
            'payment_pending' => $sum([
                AccountStatus::PaymentPending,
                AccountStatus::PaymentVerificationPending,
            ]),
            'approval_pending' => $sum([AccountStatus::ApprovalPending]),
            'active' => $sum(self::TRADING),
            'suspended' => $sum([AccountStatus::Suspended, AccountStatus::TemporarilyDisabled]),
            'expired' => $sum([AccountStatus::PackageExpired]),
            'reverification_required' => $this->reverificationCount(),
        ];
    }

    /**
     * Accounts with an outstanding KYC re-verification round.
     *
     * A round staff asked for (`requested_at` set) that the account has not
     * yet had approved — which is the whole of what "re-verification
     * required" means, rather than a status of its own.
     */
    protected function reverificationCount(): int
    {
        return BusinessAccount::query()
            ->whereHas('kycSubmissions', fn ($q) => $q
                ->whereNotNull('requested_at')
                ->whereNotIn('status', [KycStatus::Approved->value, KycStatus::Rejected->value]))
            ->count();
    }

    /**
     * Search across the identifiers a staff member actually has to hand: the
     * account's public id, its name, and the owner's name, email or mobile.
     *
     * @param  Builder<BusinessAccount>  $query
     */
    protected function searchBy(Builder $query, string $search): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('business_accounts.public_id', 'ilike', "%{$search}%")
            ->orWhere('business_accounts.name', 'ilike', "%{$search}%")
            ->orWhereHas('owner', fn ($owner) => $owner
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('email', 'ilike', "%{$search}%")
                ->orWhere('mobile', 'ilike', "%{$search}%")));
    }

    /**
     * The coarse grouping a staff member thinks in, above the 22 statuses.
     *
     * @param  Builder<BusinessAccount>  $query
     */
    protected function stateIs(Builder $query, string $state): void
    {
        match ($state) {
            'trading' => $query->whereIn('status', array_column(self::TRADING, 'value')),
            'onboarding' => $query->whereIn('status', array_column(self::ONBOARDING, 'value')),
            'halted' => $query->whereIn('status', array_column(self::HALTED, 'value')),
            'expired' => $query->where('status', AccountStatus::PackageExpired->value),
            'closed' => $query->where('status', AccountStatus::Closed->value),
            default => null,
        };
    }

    /**
     * @param  Builder<BusinessAccount>  $query
     */
    protected function kycStatusIs(Builder $query, string $status): void
    {
        /*
         * The account's *latest* round is the one that describes it. An
         * approved first round says nothing about an account whose second
         * round is sitting unsubmitted, so matching any round would file a
         * re-verifying account under "approved".
         *
         * Written as an explicit subquery with its own aliases rather than a
         * correlated `whereHas`, where the outer and inner references to
         * `kyc_submissions` would be the same name.
         */
        $query->whereIn('business_accounts.id', function ($sub) use ($status) {
            $sub->from('kyc_submissions as k')
                ->select('k.business_account_id')
                ->where('k.status', $status)
                ->whereRaw('k.round = (select max(k2.round) from kyc_submissions k2 where k2.business_account_id = k.business_account_id)');
        });
    }

    /**
     * @param  Builder<BusinessAccount>  $query
     */
    protected function reverificationIs(Builder $query, string $state): void
    {
        $outstanding = fn ($q) => $q
            ->whereNotNull('requested_at')
            ->whereNotIn('status', [KycStatus::Approved->value, KycStatus::Rejected->value]);

        match ($state) {
            'required' => $query->whereHas('kycSubmissions', $outstanding),
            'overdue' => $query->whereHas('kycSubmissions', fn ($q) => $outstanding($q)
                ->whereNotNull('deadline_at')
                ->where('deadline_at', '<', now())),
            'none' => $query->whereDoesntHave('kycSubmissions', $outstanding),
            default => null,
        };
    }

    /**
     * Which trading facilities the account's live package grants.
     *
     * @param  Builder<BusinessAccount>  $query
     */
    protected function facilityIs(Builder $query, string $facility): void
    {
        $granted = fn (PackageFeature $feature) => fn ($p) => $p
            ->where('status', UserPackageStatus::Active->value)
            ->whereHas('package.features', fn ($f) => $f
                ->where('feature', $feature->value)
                ->whereIn('value', ['1', 'true']));

        match ($facility) {
            'wholesale' => $query->whereHas('currentPackage', $granted(PackageFeature::WholesaleEnabled)),
            'dropshipping' => $query->whereHas('currentPackage', $granted(PackageFeature::DropshippingEnabled)),
            'both' => $query
                ->whereHas('currentPackage', $granted(PackageFeature::WholesaleEnabled))
                ->whereHas('currentPackage', $granted(PackageFeature::DropshippingEnabled)),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function text(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
