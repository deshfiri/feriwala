<?php

namespace App\Domain\Kyc\Actions;

use App\Domain\Account\Actions\ChangeAccountStatus;
use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Kyc\KycDeadlines;
use App\Domain\Kyc\Models\KycSubmission;
use App\Integrations\Sms\Contracts\SmsProvider;
use App\Integrations\Sms\Data\SmsMessage;
use App\Notifications\Kyc\KycDeadlineMissed;
use App\Support\Localization\Locale;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Applies the consequences of one missed KYC deadline (§7.4).
 *
 * §7.4 lists four: activation stays blocked, an active account may be
 * restricted, notifications may be sent, and the action is recorded in the
 * audit log. The first needs no action — an unactivated account is already
 * blocked, and *that is the point*: the deadline does not push it backwards,
 * it simply stops the clock running in the applicant's favour.
 *
 * Restricting an active account is the only destructive step, and only happens
 * when an administrator has turned it on. §7.4 offers it rather than requiring
 * it, and the safe reading of "may" is not to do it by default.
 *
 * Marks the round enforced before doing anything else. The sweep runs daily and
 * a round stays overdue until it is completed, so without the marker the
 * applicant would get the same SMS every morning and the audit log would fill
 * with copies of one event.
 */
class EnforceKycDeadline
{
    public function __construct(
        protected KycDeadlines $deadlines,
        protected ChangeAccountStatus $changeStatus,
        protected RecordAuditLog $audit,
        protected SmsProvider $sms,
        protected Translator $translator,
        protected DatabaseManager $database,
    ) {}

    /**
     * @return bool whether this round was acted on
     */
    public function handle(KycSubmission $submission): bool
    {
        $restricted = $this->database->transaction(function () use ($submission) {
            /** @var KycSubmission|null $locked */
            $locked = KycSubmission::query()->lockForUpdate()->find($submission->id);

            // Re-read under the lock: two workers can reach the same overdue
            // round, and the second must find it already dealt with.
            if ($locked === null || $locked->deadline_enforced_at !== null || ! $locked->isOverdue()) {
                return null;
            }

            $locked->forceFill(['deadline_enforced_at' => now()])->save();

            $account = $locked->businessAccount;
            $restricted = false;

            if ($account !== null
                && $account->isActivated()
                && $this->deadlines->restrictsActiveAccounts()
                && $account->canTransitionTo(AccountStatus::TemporarilyRestricted)) {
                $this->changeStatus->handle($account, AccountStatusChange::automatic(
                    AccountStatus::TemporarilyRestricted,
                    'The KYC deadline passed without the requested documents.',
                ));

                $restricted = true;
            }

            $this->audit->handle(new AuditEntry(
                action: 'kyc.deadline_missed',
                // No actor: a scheduled sweep did this, not a person, and
                // naming one would be a lie an investigation could act on.
                actorType: 'system',
                auditableType: KycSubmission::class,
                auditableId: $locked->id,
                after: [
                    'deadline_at' => $locked->deadline_at?->toIso8601String(),
                    'account_restricted' => $restricted,
                ],
                reason: 'KYC deadline passed (§7.4).',
                accountId: $account?->id,
                module: 'kyc',
            ));

            $submission->setRawAttributes($locked->getAttributes(), sync: true);

            return $restricted;
        });

        if ($restricted === null) {
            return false;
        }

        $this->notify($submission, $restricted);

        return true;
    }

    /**
     * Tell the owner, on both channels §7.4 names.
     *
     * After the transaction, and never allowed to undo it: the deadline passed
     * whether or not a text message got through, and rolling back a recorded
     * status change because an SMS gateway was down would leave the account in
     * a state contradicting its own audit entry.
     */
    protected function notify(KycSubmission $submission, bool $restricted): void
    {
        $owner = $submission->businessAccount?->owner;

        if ($owner === null) {
            return;
        }

        $owner->notify(new KycDeadlineMissed($restricted));

        if (blank($owner->mobile)) {
            return;
        }

        try {
            $locale = Locale::parse($owner->locale);

            $this->sms->send(new SmsMessage(
                to: (string) $owner->mobile,
                body: $this->translator->get('sms.kyc_deadline_missed', [], $locale->value),
                locale: $locale,
                event: 'kyc_deadline_missed',
                userId: $owner->id,
            ));
        } catch (Throwable) {
            // The mail has gone and the audit entry is written. A failed text
            // is not a reason to lose either.
        }
    }
}
