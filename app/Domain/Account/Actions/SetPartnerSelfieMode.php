<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Kyc\Enums\KycSelfieMode;
use App\Domain\Kyc\KycSelfiePolicy;
use App\Domain\Kyc\Models\KycSubmission;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * An administrator decides whether one partner's identity verification asks
 * for a selfie (D39 step 4), overriding the platform setting for them alone.
 *
 * Applies at once to a round the partner has not yet submitted; a submitted
 * round keeps what it asked for, so a reviewer judges it on its own terms.
 * Needs a reason, and is audited.
 */
class SetPartnerSelfieMode
{
    public function __construct(
        protected KycSelfiePolicy $selfies,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /** Null takes the override away: the partner follows the platform setting again. */
    public function handle(BusinessAccount $account, User $actor, ?KycSelfieMode $mode, string $reason): BusinessAccount
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Say why this partner is treated differently.');
        }

        return $this->database->transaction(function () use ($account, $actor, $mode, $reason): BusinessAccount {
            /** @var BusinessAccount $locked */
            $locked = BusinessAccount::query()->lockForUpdate()->findOrFail($account->id);
            $before = $locked->kyc_selfie_mode;

            $locked->forceFill(['kyc_selfie_mode' => $mode?->value])->save();

            $effective = $this->selfies->forAccount($locked)?->value;

            KycSubmission::query()
                ->where('business_account_id', $locked->id)
                ->whereNull('submitted_at')
                ->get()
                ->filter(fn (KycSubmission $round) => $round->status->isEditable())
                ->each(fn (KycSubmission $round) => $round->forceFill(['selfie_mode' => $effective])->save());

            $this->audit->handle(new AuditEntry(
                action: 'kyc.partner_selfie_setting_changed',
                actorId: $actor->id,
                auditableType: BusinessAccount::class,
                auditableId: $locked->id,
                before: ['mode' => $before ?? 'platform'],
                after: ['mode' => $mode === null ? 'platform' : $mode->value],
                reason: trim($reason),
                accountId: $locked->id,
                module: 'kyc',
                isSensitive: true,
            ));

            return $locked;
        });
    }
}
