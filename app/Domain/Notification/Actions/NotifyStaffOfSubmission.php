<?php

namespace App\Domain\Notification\Actions;

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Domain\Notification\Enums\StaffQueue;
use App\Models\User;
use App\Notifications\Staff\WorkItemSubmitted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Tells the platform staff who can act on a queue that something has arrived in it.
 *
 * Recipients are whoever holds the queue's `view` permission, plus Super Admins,
 * and only people with platform access — a business account's own staff, and
 * Suppliers (a separate guard), are never told what is waiting in the platform's
 * queues.
 *
 * Called after the submission has committed. A failure to notify must never
 * undo the submission itself, so it is reported and swallowed: the dashboard
 * count still shows the work, only the nudge is lost.
 *
 * Each recipient's notification id is derived from the queue, the submission and
 * the recipient, so announcing the same submission twice — a retried job, a
 * double click — writes one bell entry.
 */
class NotifyStaffOfSubmission
{
    /**
     * @param  string  $subjectId  The submission's public id — only ever used to make the notification id stable.
     * @param  string|null  $reference  The submission's own reference, shown in the bell. Never a name or a detail.
     */
    public function handle(StaffQueue $queue, string $subjectId, ?string $reference = null): void
    {
        try {
            foreach ($this->recipients($queue) as $recipient) {
                $notification = new WorkItemSubmitted($queue, $reference);
                $notification->id = Uuid::uuid5(
                    Uuid::NAMESPACE_URL,
                    "feriwala:{$queue->event()}:{$subjectId}:{$recipient->public_id}",
                )->toString();

                Notification::send($recipient, $notification);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return Collection<int, User>
     */
    public function recipients(StaffQueue $queue): Collection
    {
        return User::permission($queue->permission())->get()
            ->merge(User::role(PlatformRole::SuperAdmin->value)->get())
            ->unique('id')
            ->filter(fn (User $user) => $user->hasPlatformAccess() && ! CatalogPolicy::isBusinessIdentity($user))
            ->values();
    }
}
