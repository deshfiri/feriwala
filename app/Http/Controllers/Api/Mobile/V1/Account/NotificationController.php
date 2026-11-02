<?php

namespace App\Http\Controllers\Api\Mobile\V1\Account;

use App\Domain\Notification\Actions\MarkNotificationsRead;
use App\Domain\Notification\Queries\RecentNotifications;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Resources\Mobile\Account\NotificationResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The notification bell. Acts only on the signed-in person's own notifications:
 * there is no user identifier in any address (§31.3). Reachable before activation.
 */
class NotificationController extends MobileController
{
    /**
     * My notifications
     *
     * The latest notifications (at most ten, the same list as the web bell),
     * newest first, with `unread_count` counting every unread one, not only
     * those listed. Titles and descriptions follow the request language.
     */
    public function index(Request $request, RecentNotifications $recent): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return NotificationResource::collection($recent->forUser($user))
            ->additional(['unread_count' => $recent->unreadCountFor($user)]);
    }

    /**
     * Mark one notification read
     *
     * `404` when the notification does not exist or is not the caller's.
     */
    public function read(Request $request, string $notification, MarkNotificationsRead $markRead): Response
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($markRead->one($user, $notification), 404);

        return response()->noContent();
    }

    /**
     * Mark all notifications read
     */
    public function readAll(Request $request, MarkNotificationsRead $markRead): Response
    {
        /** @var User $user */
        $user = $request->user();

        $markRead->all($user);

        return response()->noContent();
    }
}
