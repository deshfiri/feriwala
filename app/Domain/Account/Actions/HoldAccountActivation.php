<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Models\User;
use App\Support\Concurrency\DistributedLock;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Takes one account off the automatic activation path, or puts it back on
 * (D27).
 *
 * Activation is automatic once its conditions are met, which leaves no natural
 * moment for "wait — look at this one". A hold is that moment, made explicit:
 * a named member of staff, a reason, a time. It is the exception, and it reads
 * like one in the record.
 *
 * What a hold does **not** do is block activation. A reviewer who satisfies
 * themselves can still activate a held account through the ordinary manual
 * route, and nothing here touches {@see ActivateAccount}. The hold turns off
 * the automatic path only, so the decision returns to a person rather than
 * being taken away from everyone.
 *
 * Serialised on the same key the automatic path locks, so a hold placed while a
 * settlement is in flight cannot land half a step too late — either the hold
 * wins and the account waits, or the activation wins and the hold arrives to
 * find an account that is already active, which it refuses rather than
 * recording a hold over something already decided.
 */
class HoldAccountActivation
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
        protected DistributedLock $lock,
    ) {}

    /**
     * @throws InvalidArgumentException when the reason is empty, or the account
     *                                  is already active
     */
    public function hold(BusinessAccount $account, User $heldBy, string $reason): BusinessAccount
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A hold needs a reason, which is recorded against the account.');
        }

        return $this->lock->run(
            key: 'account:activation:'.$account->id,
            callback: fn () => $this->database->transaction(function () use ($account, $heldBy, $reason) {
                /** @var BusinessAccount $locked */
                $locked = BusinessAccount::query()->lockForUpdate()->findOrFail($account->id);

                if ($locked->isActivated()) {
                    throw new InvalidArgumentException('This account is already active; holding it would change nothing.');
                }

                $locked->forceFill([
                    'activation_hold_reason' => trim($reason),
                    'activation_held_at' => now(),
                    'activation_held_by' => $heldBy->id,
                ])->save();

                $this->audit->handle(new AuditEntry(
                    action: 'account.activation_held',
                    actorId: $heldBy->id,
                    auditableType: BusinessAccount::class,
                    auditableId: $locked->id,
                    after: ['activation_held' => true],
                    reason: trim($reason),
                    accountId: $locked->id,
                    module: 'account',
                    isSensitive: true,
                ));

                $account->setRawAttributes($locked->getAttributes(), sync: true);

                return $account;
            }),
            ttlSeconds: 30,
            waitSeconds: 10,
        );
    }

    /**
     * Put the account back on the automatic path.
     *
     * Releasing does not itself activate. The conditions are re-checked by
     * whatever next touches the account — or by a reviewer, there and then —
     * rather than a release quietly becoming an activation nobody asked for.
     *
     * @throws InvalidArgumentException when the account is not held
     */
    public function release(BusinessAccount $account, User $releasedBy, string $reason): BusinessAccount
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Releasing a hold needs a reason, which is recorded against the account.');
        }

        return $this->lock->run(
            key: 'account:activation:'.$account->id,
            callback: fn () => $this->database->transaction(function () use ($account, $releasedBy, $reason) {
                /** @var BusinessAccount $locked */
                $locked = BusinessAccount::query()->lockForUpdate()->findOrFail($account->id);

                if (! $locked->activationIsHeld()) {
                    throw new InvalidArgumentException('This account is not held.');
                }

                $heldReason = $locked->activation_hold_reason;

                $locked->forceFill([
                    'activation_hold_reason' => null,
                    'activation_held_at' => null,
                    'activation_held_by' => null,
                ])->save();

                $this->audit->handle(new AuditEntry(
                    action: 'account.activation_hold_released',
                    actorId: $releasedBy->id,
                    auditableType: BusinessAccount::class,
                    auditableId: $locked->id,
                    before: ['activation_held' => true, 'hold_reason' => $heldReason],
                    after: ['activation_held' => false],
                    reason: trim($reason),
                    accountId: $locked->id,
                    module: 'account',
                    isSensitive: true,
                ));

                $account->setRawAttributes($locked->getAttributes(), sync: true);

                return $account;
            }),
            ttlSeconds: 30,
            waitSeconds: 10,
        );
    }
}
