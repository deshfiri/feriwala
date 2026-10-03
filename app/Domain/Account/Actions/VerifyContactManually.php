<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Models\StaffContactVerification;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Actions\AdvanceSupplierPastVerification;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use App\Notifications\Account\ContactVerifiedByStaff;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Staff confirm a person's email or mobile on their behalf -- for a
 * Client/Partner owner or a Supplier.
 *
 * Meant for correcting an onboarding lockout (a code that never arrives, a
 * mailbox that filters the link), not for skipping verification casually:
 *
 * - **A reason is mandatory** and recorded, with the actor and time, in both
 *   the audit log (sensitive) and an append-only {@see StaffContactVerification}
 *   row that marks the channel as staff-verified.
 * - **Never overwrites evidence.** A channel already verified -- by the person
 *   or by staff -- is refused, so the original verification stays the record
 *   and there is exactly one event per channel.
 * - **Goes through the funnel, not around it.** After confirming, the account
 *   or Supplier advances only if verification is now genuinely complete under
 *   the current requirement ({@see AdvanceAccountPastVerification},
 *   {@see AdvanceSupplierPastVerification}); nothing is activated here.
 * - **Tells the person.** They are notified which channel was confirmed.
 */
class VerifyContactManually
{
    public const EMAIL = 'email';

    public const MOBILE = 'mobile';

    public function __construct(
        protected DatabaseManager $database,
        protected RecordAuditLog $audit,
        protected AdvanceAccountPastVerification $advanceAccount,
        protected AdvanceSupplierPastVerification $advanceSupplier,
    ) {}

    /**
     * @param  User|Supplier  $identity
     * @param  string  $channel  'email' or 'mobile'
     *
     * @throws InvalidArgumentException
     */
    public function handle(User $actor, Model $identity, string $channel, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required and is recorded against this verification.');
        }

        if (! in_array($channel, [self::EMAIL, self::MOBILE], true)) {
            throw new InvalidArgumentException('Unknown contact channel.');
        }

        $column = $channel === self::EMAIL ? 'email_verified_at' : 'mobile_verified_at';
        $type = $identity instanceof Supplier ? 'supplier' : 'user';

        $this->database->transaction(function () use ($actor, $identity, $channel, $reason, $column, $type) {
            /** @var User|Supplier $locked */
            $locked = $identity::query()->lockForUpdate()->findOrFail($identity->getKey());

            if ($channel === self::MOBILE && blank($locked->mobile)) {
                throw new InvalidArgumentException('There is no mobile number on file to confirm.');
            }

            if ($locked->{$column} !== null) {
                throw new InvalidArgumentException('This '.$channel.' is already verified; the original verification is kept.');
            }

            $locked->forceFill([$column => now()])->save();

            StaffContactVerification::query()->create([
                'identity_type' => $type,
                'identity_id' => $locked->getKey(),
                'channel' => $channel,
                'verified_by' => $actor->id,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            $this->audit->handle(new AuditEntry(
                action: 'identity.'.$channel.'_verified_by_staff',
                actorId: $actor->id,
                auditableType: $locked::class,
                auditableId: $locked->getKey(),
                before: [$column => null],
                after: [$column => $locked->{$column}?->toIso8601String(), 'verified_by' => 'staff'],
                reason: $reason,
                module: $type === 'supplier' ? 'supplier' : 'account',
                isSensitive: true,
            ));

            if ($locked instanceof Supplier) {
                $this->advanceSupplier->handle($locked);
            } elseif ($locked->businessAccount !== null) {
                $this->advanceAccount->handle($locked->businessAccount, 'Verification completed by staff.');
            }
        });

        $identity->refresh()->notify(new ContactVerifiedByStaff($channel));
    }
}
