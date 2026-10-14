<?php

namespace App\Domain\Notification\Enums;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Enums\DeletionRequestStatus;
use App\Domain\Account\Models\AccountDeletionRequest;
use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Billing\Models\RefundRequest;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Notification\Actions\NotifyStaffOfSubmission;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Enums\LotStatus;
use App\Domain\Supplier\Enums\StockUpdateStatus;
use App\Domain\Supplier\Enums\SupplierKycStatus;
use App\Domain\Supplier\Enums\SupplierWithdrawalStatus;
use App\Domain\Supplier\Models\SupplierKycSubmission;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Domain\Supplier\Models\SupplierProductListingLot;
use App\Domain\Supplier\Models\SupplierStockUpdate;
use App\Domain\Supplier\Models\SupplierWithdrawal;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Withdrawal\Enums\AccountWithdrawalStatus;
use App\Domain\Withdrawal\Models\AccountWithdrawal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Every queue of work that arrives from outside the platform team and waits for
 * one of them to act (§33.2).
 *
 * One definition drives three things that must never disagree: who is told when
 * something arrives, what the dashboard counts as still waiting, and where the
 * bell notification takes them. A queue added here is notified, counted and
 * linked — it cannot be notified but missing from the dashboard, or counted but
 * silent.
 *
 * Each queue is gated by the same `view` permission as the screen it links to,
 * so a person is only told about, and only shown a figure for, work they could
 * open anyway.
 */
enum StaffQueue: string
{
    case Kyc = 'kyc';
    case SupplierKyc = 'supplier_kyc';
    case SupplierListing = 'supplier_listing';
    case SupplierListingLot = 'supplier_listing_lot';
    case SupplierStockUpdate = 'supplier_stock_update';
    case AccountWithdrawal = 'account_withdrawal';
    case SupplierWithdrawal = 'supplier_withdrawal';
    case OrderReturn = 'order_return';
    case RefundRequest = 'refund_request';
    case WebsiteRequest = 'website_request';
    case AccountDeletion = 'account_deletion';
    case SupplierDeletion = 'supplier_deletion';

    /**
     * The `notification.events.*` key and the stored `event` for this queue.
     */
    public function event(): string
    {
        return 'queue.'.$this->value;
    }

    public static function fromEvent(string $event): ?self
    {
        return str_starts_with($event, 'queue.') ? self::tryFrom(substr($event, 6)) : null;
    }

    public function module(): PermissionModule
    {
        return match ($this) {
            self::Kyc => PermissionModule::Kyc,
            self::SupplierKyc => PermissionModule::SupplierKyc,
            self::SupplierListing, self::SupplierListingLot => PermissionModule::SupplierListing,
            self::SupplierStockUpdate => PermissionModule::SupplierStock,
            self::AccountWithdrawal, self::SupplierWithdrawal => PermissionModule::Withdrawal,
            self::OrderReturn => PermissionModule::Order,
            self::RefundRequest => PermissionModule::Payment,
            self::WebsiteRequest => PermissionModule::Website,
            self::AccountDeletion => PermissionModule::Account,
            self::SupplierDeletion => PermissionModule::Supplier,
        };
    }

    /**
     * The dashboard card key, which is also the `dashboard.admin.cards.*` lang
     * key. Kyc, SupplierKyc and SupplierListing keep the cards the dashboard
     * already had under those keys.
     */
    public function cardKey(): string
    {
        return match ($this) {
            self::Kyc => 'kyc',
            self::SupplierKyc => 'supplier_kyc',
            self::SupplierListing => 'supplier_listings',
            self::SupplierListingLot => 'supplier_listing_lots',
            self::SupplierStockUpdate => 'supplier_stock',
            self::AccountWithdrawal => 'account_withdrawals',
            self::SupplierWithdrawal => 'supplier_withdrawals',
            self::OrderReturn => 'returns',
            self::RefundRequest => 'refunds',
            self::WebsiteRequest => 'website_requests',
            self::AccountDeletion => 'account_deletions',
            self::SupplierDeletion => 'supplier_deletions',
        };
    }

    /**
     * The permission that opens the queue. Viewing is enough for every queue but
     * the deletion requests: those hold someone's request to be erased, and only
     * a person who may actually delete is shown them.
     */
    public function permission(): string
    {
        return PermissionCatalogue::name(
            $this->module(),
            match ($this) {
                self::AccountDeletion, self::SupplierDeletion => PermissionAction::Delete,
                default => PermissionAction::View,
            },
        );
    }

    public function mayBeSeenBy(User $user): bool
    {
        return $user->can($this->permission());
    }

    /**
     * Where a staff member goes to act on this queue.
     */
    public function route(): string
    {
        return match ($this) {
            self::Kyc => route('admin.kyc.index'),
            self::SupplierKyc => route('admin.suppliers.index'),
            self::SupplierListing => route('admin.supplier-listings.index'),
            self::SupplierListingLot => route('admin.supplier-listing-lots.index'),
            self::SupplierStockUpdate => route('admin.supplier-stock.index'),
            self::AccountWithdrawal => route('admin.account-withdrawals.index'),
            self::SupplierWithdrawal => route('admin.supplier-withdrawals.index'),
            self::OrderReturn => route('admin.returns.index'),
            self::RefundRequest => route('admin.payments.index'),
            self::WebsiteRequest => route('admin.websites.index'),
            self::AccountDeletion, self::SupplierDeletion => route('admin.deletions.index'),
        };
    }

    /**
     * @return class-string<Model>
     */
    public function model(): string
    {
        return match ($this) {
            self::Kyc => KycSubmission::class,
            self::SupplierKyc => SupplierKycSubmission::class,
            self::SupplierListing => SupplierProductListing::class,
            self::SupplierListingLot => SupplierProductListingLot::class,
            self::SupplierStockUpdate => SupplierStockUpdate::class,
            self::AccountWithdrawal => AccountWithdrawal::class,
            self::SupplierWithdrawal => SupplierWithdrawal::class,
            self::OrderReturn => OrderReturn::class,
            self::RefundRequest => RefundRequest::class,
            self::WebsiteRequest => Website::class,
            self::AccountDeletion, self::SupplierDeletion => AccountDeletionRequest::class,
        };
    }

    /**
     * The statuses in which a row is still waiting on a person.
     *
     * @return array<int, string>
     */
    public function waitingStatuses(): array
    {
        return array_map(fn (\BackedEnum $status) => (string) $status->value, match ($this) {
            self::Kyc => [KycStatus::Submitted, KycStatus::UnderReview],
            self::SupplierKyc => [SupplierKycStatus::Submitted, SupplierKycStatus::UnderReview],
            self::SupplierListing => [ListingStatus::Submitted, ListingStatus::UnderReview],
            self::SupplierListingLot => [LotStatus::Submitted, LotStatus::UnderReview],
            self::SupplierStockUpdate => [StockUpdateStatus::Pending],
            self::AccountWithdrawal => [AccountWithdrawalStatus::Requested, AccountWithdrawalStatus::UnderReview],
            self::SupplierWithdrawal => [SupplierWithdrawalStatus::Requested, SupplierWithdrawalStatus::UnderReview],
            self::OrderReturn => [ReturnStatus::Requested],
            self::RefundRequest => [RefundStatus::Requested],
            self::WebsiteRequest => [WebsiteStatus::SetupPending],
            self::AccountDeletion, self::SupplierDeletion => [DeletionRequestStatus::Pending],
        });
    }

    /**
     * Tells the staff who can act on this queue that `$submission` has arrived.
     *
     * The one call every submit/request action makes once it has committed.
     */
    public function announce(Model $submission): void
    {
        app(NotifyStaffOfSubmission::class)->handle(
            $this,
            (string) $submission->getAttribute('public_id'),
            $this->referenceFor($submission),
        );
    }

    /**
     * A reference that is safe to show in a bell.
     *
     * The submission's own reference where it has one (a withdrawal's `WDR-…`,
     * a return's `RET-…`). Where it has none, a short code and the tail of its
     * public id — enough for a reviewer to match it to the row, and never a
     * name, an amount, a business or any detail the submission carries.
     */
    public function referenceFor(Model $submission): string
    {
        $own = array_key_exists('reference', $submission->getAttributes())
            ? $submission->getAttribute('reference')
            : null;

        if (is_string($own) && $own !== '') {
            return $own;
        }

        $prefix = match ($this) {
            self::Kyc => 'KYC',
            self::SupplierKyc => 'SKC',
            self::SupplierStockUpdate => 'STK',
            self::RefundRequest => 'RFD',
            self::WebsiteRequest => 'WEB',
            self::AccountDeletion, self::SupplierDeletion => 'DEL',
            default => strtoupper(substr($this->value, 0, 3)),
        };

        return $prefix.'-'.strtoupper(substr((string) $submission->getAttribute('public_id'), -8));
    }

    /**
     * Rows still waiting on a person.
     *
     * @return Builder<Model>
     */
    public function waiting(): Builder
    {
        return $this->rows()->whereIn('status', $this->waitingStatuses());
    }

    /**
     * Every row that belongs to this queue, waiting or not. Two queues can share
     * a model — a deletion request is a person's or a Supplier's — so each reads
     * only its own half, and counting both would count every request twice.
     *
     * @return Builder<Model>
     */
    public function rows(): Builder
    {
        $query = $this->model()::query();

        return match ($this) {
            self::AccountDeletion => $query->whereNotNull('user_id'),
            self::SupplierDeletion => $query->whereNotNull('supplier_id'),
            default => $query,
        };
    }
}
