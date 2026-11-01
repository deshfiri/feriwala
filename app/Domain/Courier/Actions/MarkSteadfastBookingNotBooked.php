<?php

namespace App\Domain\Courier\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Courier\Enums\CourierBookingStatus;
use App\Domain\Courier\Exceptions\CourierBookingRefused;
use App\Domain\Courier\Models\CourierBookingAttempt;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * A person confirms, from Steadfast's own merchant panel, that an uncertain
 * booking never reached it -- the way out when the invoice lookup cannot say
 * (its not-found answer is not documented, so it can stay inconclusive).
 *
 * Audited with the person and their reason: after this, booking may be tried
 * again, and if they were wrong the parcel is booked twice.
 */
class MarkSteadfastBookingNotBooked
{
    public function __construct(
        protected DatabaseManager $database,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @throws InvalidArgumentException when no reason is given
     * @throws CourierBookingRefused when the attempt is not uncertain
     */
    public function handle(CourierBookingAttempt $attempt, User $actor, string $reason): CourierBookingAttempt
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required.');
        }

        return $this->database->transaction(function () use ($attempt, $actor, $reason): CourierBookingAttempt {
            /** @var CourierBookingAttempt $locked */
            $locked = CourierBookingAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            if ($locked->status !== CourierBookingStatus::Uncertain) {
                throw CourierBookingRefused::because('not_uncertain');
            }

            $locked->forceFill([
                'status' => CourierBookingStatus::Failed,
                'failure_code' => 'staff_confirmed_not_booked',
                'failure_message' => __('courier.booking.staff_confirmed_message'),
                'completed_at' => now(),
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: 'courier.booking_confirmed_not_booked',
                actorId: $actor->id,
                auditableType: CourierBookingAttempt::class,
                auditableId: $locked->id,
                after: ['status' => 'failed'],
                reason: $reason,
                module: 'courier',
                isSensitive: true,
            ));

            return $locked;
        });
    }
}
