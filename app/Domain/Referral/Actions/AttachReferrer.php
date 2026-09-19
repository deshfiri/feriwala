<?php

namespace App\Domain\Referral\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Referral\Enums\ReferralAttachment;
use App\Domain\Referral\Exceptions\ReferralRefused;
use App\Domain\Referral\Models\AccountReferral;
use App\Domain\Referral\Queries\ReferralHierarchy;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

/**
 * Give an account its direct referrer, or correct it (§25.1, §25.5, D24, P7-11).
 *
 * Registration attaches the referrer whose code the owner typed. A person
 * attaches or corrects one afterwards — a genuine code mistyped at sign-up —
 * only **before a qualifying event has locked it**, with a reason, audited
 * with what it was before. After that the link is part of the financial
 * record and never changes.
 *
 * Self-referral and cycles are refused here with a message a person can act
 * on, and again by the database, which serialises hierarchy writes and walks
 * the chain itself — so a race or a direct write cannot slip one through.
 */
class AttachReferrer
{
    public function __construct(
        protected DatabaseManager $database,
        protected ReferralHierarchy $hierarchy,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * At registration: the code the owner typed, already resolved to an active
     * referrer (§25.1).
     */
    public function atRegistration(BusinessAccount $account, BusinessAccount $referrer, ?string $code): AccountReferral
    {
        return $this->write($account, $referrer, [
            'referral_code' => $code,
            'attached_via' => ReferralAttachment::Registration,
        ]);
    }

    /**
     * By a person, with a reason.
     *
     * @throws ReferralRefused
     */
    public function byStaff(BusinessAccount $account, BusinessAccount $referrer, User $actor, string $reason): AccountReferral
    {
        if (! $referrer->canTransact()) {
            throw ReferralRefused::referrerNotActive();
        }

        return $this->database->transaction(function () use ($account, $referrer, $actor, $reason) {
            /** @var AccountReferral|null $existing */
            $existing = AccountReferral::query()
                ->where('referred_account_id', $account->id)
                ->lockForUpdate()
                ->first();

            if ($existing?->isLocked()) {
                throw ReferralRefused::locked();
            }

            $before = $existing?->referrer_account_id;

            $link = $this->write($account, $referrer, [
                'referral_code' => null,
                'attached_via' => ReferralAttachment::Staff,
                'attached_by' => $actor->id,
                'reason' => $reason,
            ]);

            $this->audit->handle(new AuditEntry(
                action: 'referral.referrer_attached',
                actorId: $actor->id,
                auditableType: BusinessAccount::class,
                auditableId: $account->id,
                before: ['referrer_account' => $before === null ? null : BusinessAccount::query()->whereKey($before)->value('public_id')],
                after: ['referrer_account' => $referrer->public_id],
                reason: $reason,
                accountId: $account->id,
                module: 'referral',
            ));

            return $link;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ReferralRefused
     */
    protected function write(BusinessAccount $account, BusinessAccount $referrer, array $attributes): AccountReferral
    {
        if ($account->id === $referrer->id) {
            throw ReferralRefused::selfReferral();
        }

        if ($this->hierarchy->isSelfOrAncestor($account->id, $referrer->id)) {
            throw ReferralRefused::circular();
        }

        try {
            // A savepoint: a refusal from the database's own guard must not
            // abort a registration or a request's surrounding transaction.
            return $this->database->transaction(fn () => AccountReferral::query()->updateOrCreate(
                ['referred_account_id' => $account->id],
                ['referrer_account_id' => $referrer->id, ...$attributes],
            ));
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'its own ancestor')) {
                throw ReferralRefused::circular();
            }

            if (str_contains($exception->getMessage(), 'locked by a qualifying event')) {
                throw ReferralRefused::locked();
            }

            throw $exception;
        }
    }
}
