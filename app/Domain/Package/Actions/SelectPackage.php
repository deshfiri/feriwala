<?php

namespace App\Domain\Package\Actions;

use App\Domain\Account\Actions\ChangeAccountStatus;
use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Package\Data\SubscriptionTerms;
use App\Domain\Package\Enums\SubscriptionSource;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Records the package an applicant has chosen (§8.2).
 *
 * Selecting is not buying. The subscription is created awaiting payment and
 * grants nothing until activation — §5.1 puts payment and administrative
 * approval between choosing and using.
 *
 * Choosing again before paying replaces the previous choice rather than adding
 * a second one, so someone comparing options does not accumulate a queue of
 * unpaid subscriptions.
 */
class SelectPackage
{
    public function __construct(
        protected ChangeAccountStatus $changeStatus,
        protected DatabaseManager $database,
    ) {}

    public function handle(BusinessAccount $account, Package $package): UserPackage
    {
        if (! $package->isAvailable()) {
            throw new RuntimeException('That package is not currently available.');
        }

        return $this->database->transaction(function () use ($account, $package) {
            // Supersede rather than delete: an abandoned choice is history worth
            // keeping, and deleting rows a payment might reference is how
            // orphaned payments happen.
            UserPackage::query()
                ->where('business_account_id', $account->id)
                ->where('status', UserPackageStatus::PendingPayment)
                ->lockForUpdate()
                ->update(['status' => UserPackageStatus::Superseded]);

            $subscription = UserPackage::create([
                'business_account_id' => $account->id,
                'package_id' => $package->id,
                'status' => UserPackageStatus::PendingPayment,
                'source' => SubscriptionSource::Purchase,
                // The price at the moment of choosing. An administrator editing
                // the package afterwards must not silently change what this
                // applicant was quoted.
                'paid_fee_minor' => $package->fee_minor,
                'currency_code' => $package->fee_minor->currency->value,

                /*
                 * And everything else the package says, for the same reason
                 * (§8.3). Entitlements read this rather than the live row, so
                 * an administrator lowering a staff limit changes what future
                 * buyers get — not what this account already agreed to.
                 */
                'terms' => SubscriptionTerms::capture($package->load(['features', 'charges']))->toArray(),
                'terms_captured_at' => now(),
            ]);

            if ($account->canTransitionTo(AccountStatus::PaymentPending)) {
                $this->changeStatus->handle($account, new AccountStatusChange(
                    to: AccountStatus::PaymentPending,
                    reason: 'Selected the '.$package->name.' package.',
                ));
            }

            return $subscription;
        });
    }
}
