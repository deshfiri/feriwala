<?php

namespace App\Http\Controllers\Erp;

use App\Domain\Notification\Actions\MarkNotificationsRead;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The header bell's read state. Acts only on the signed-in person's own
 * notifications — there is no user identifier in any of these addresses.
 */
class NotificationController extends Controller
{
    public function read(Request $request, string $notification, MarkNotificationsRead $markRead): RedirectResponse|Response
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($markRead->one($user, $notification), 404);

        // A background fetch (following the row's link) wants no redirect.
        return $request->expectsJson() ? response()->noContent() : back();
    }

    public function readAll(Request $request, MarkNotificationsRead $markRead): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $markRead->all($user);

        return back();
    }
}
