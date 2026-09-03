<?php

namespace App\Domain\Package\Actions;

use App\Domain\Account\Actions\ChangeAccountStatus;
use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Models\User;
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

    public function handle(User $user, Package $package): UserPackage
    {
        if (! $package->isAvailable()) {
            throw new RuntimeException('That package is not currently available.');
        }

        return $this->database->transaction(function () use ($user, $package) {
            // Supersede rather than delete: an abandoned choice is history worth
            // keeping, and deleting rows a payment might reference is how
            // orphaned payments happen.
            UserPackage::query()
                ->where('user_id', $user->id)
                ->where('status', UserPackageStatus::PendingPayment)
                ->lockForUpdate()
                ->update(['status' => UserPackageStatus::Superseded]);

            $subscription = UserPackage::create([
                'user_id' => $user->id,
                'package_id' => $package->id,
                'status' => UserPackageStatus::PendingPayment,
                'source' => 'purchase',
                // The price at the moment of choosing. An administrator editing
                // the package afterwards must not silently change what this
                // applicant was quoted.
                'paid_fee_minor' => $package->fee_minor,
                'currency_code' => $package->fee_minor->currency->value,
            ]);

            if ($user->canTransitionTo(AccountStatus::PaymentPending)) {
                $this->changeStatus->handle($user, new AccountStatusChange(
                    to: AccountStatus::PaymentPending,
                    reason: 'Selected the '.$package->name.' package.',
                ));
            }

            return $subscription;
        });
    }
}
