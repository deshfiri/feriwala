<?php

namespace App\Domain\Notification\Actions;

use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use App\Notifications\Requests\RequestDecided;
use Illuminate\Support\Facades\Notification;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Tells the person who asked that staff have decided (§33.2).
 *
 * The recipient is whoever made the request — passed in by the deciding action,
 * which knows the owner — so there is no way to address this at somebody else's
 * request. Called after the decision has committed; a failure to notify is
 * reported and never undoes the decision.
 *
 * The notification id is derived from the request and its outcome, so a retried
 * decision writes one bell entry. A request is only decided once, so the outcome
 * in the key never collides with a later one.
 */
class NotifyRequesterOfDecision
{
    /**
     * @param  string  $subjectId  The request's public id — only used to make the notification id stable.
     * @param  array<string, string>  $links
     */
    public function handle(
        User|Supplier|null $requester,
        string $kind,
        bool $approved,
        string $subjectId,
        ?string $reference = null,
        array $links = [],
    ): void {
        if ($requester === null) {
            return;
        }

        try {
            $notification = new RequestDecided($kind, $approved, $reference, $links);
            $notification->id = Uuid::uuid5(
                Uuid::NAMESPACE_URL,
                "feriwala:{$notification->event()}:{$subjectId}:{$requester->public_id}",
            )->toString();

            Notification::send($requester, $notification);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
