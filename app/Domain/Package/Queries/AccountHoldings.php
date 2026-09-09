<?php

namespace App\Domain\Package\Queries;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Package\DowngradeGuard;
use App\Domain\Package\Enums\PackageFeature;

/**
 * What an account currently holds against the limits a downgrade guards (D16).
 *
 * {@see DowngradeGuard} deliberately takes counts rather than querying for them:
 * the caller knows what it is counting, and a guard that guessed would
 * eventually count something subtly different from the thing the limit governs.
 * This is that caller, in one place, so the figure the screen shows and the
 * figure the action enforces are the same number.
 *
 * Only staff can be counted today. Published products and partner websites are
 * governed by modules that do not exist yet, and **zero is the honest answer
 * until they do** — an account cannot be over a limit on things it cannot yet
 * have. Each returns a real count the moment its module lands, and the guard
 * needs no change when it does.
 */
class AccountHoldings
{
    /**
     * @return array<string, int> keyed by PackageFeature value
     */
    public function counts(BusinessAccount $account): array
    {
        return [
            PackageFeature::StaffLimit->value => $account->memberships()->count(),

            // Awaiting the catalogue and website modules. Listed rather than
            // omitted so the guard reads every feature it guards and nothing
            // silently drops out of the check when they arrive.
            PackageFeature::ProductPublishLimit->value => 0,
            PackageFeature::WebsiteLimit->value => 0,
        ];
    }
}
