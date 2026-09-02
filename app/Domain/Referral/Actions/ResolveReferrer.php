<?php

namespace App\Domain\Referral\Actions;

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Referral\ReferralCode;
use App\Models\User;

/**
 * Finds the account behind a referral code offered at registration (§25).
 *
 * Returns null rather than throwing when a code does not resolve. A mistyped
 * code must not block someone from registering — they simply register without a
 * referrer, and support can attach one later if it was genuine.
 *
 * Only an **active** account can refer (§25.1). Allowing a suspended or closed
 * account to keep earning referral rewards would make suspension meaningless.
 */
class ResolveReferrer
{
    public function __construct(
        protected ReferralCode $codes,
    ) {}

    public function handle(?string $code): ?User
    {
        if ($code === null || $code === '') {
            return null;
        }

        $normalised = $this->codes->normalise($code);

        if (! $this->codes->isWellFormed($normalised)) {
            return null;
        }

        return User::query()
            ->where('referral_code', $normalised)
            ->where('status', AccountStatus::Active)
            ->first();
    }
}
