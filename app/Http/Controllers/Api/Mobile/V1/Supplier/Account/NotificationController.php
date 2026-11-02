<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Account;

use App\Domain\Notification\Actions\MarkNotificationsRead;
use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Resources\Mobile\Supplier\Account\SupplierNotificationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * A Supplier's own notifications. Always reached through the signed-in
 * Supplier's relationship, so another supplier's notification id is a `404`.
 */
class NotificationController extends MobileController
{
    /**
     * Notifications
     *
     * The supplier's own notifications, newest first, 20 to a page, worded in
     * the request language.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var Supplier $supplier */
        $supplier = $request->user();

        return SupplierNotificationResource::collection(
            $supplier->notifications()->latest()->paginate(20),
        );
    }

    /**
     * Mark all notifications read
     *
     * Marks every unread notification of this supplier as read.
     */
    public function markAllRead(Request $request, MarkNotificationsRead $markRead): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user();

        $markRead->all($supplier);

        return response()->noContent();
    }

    /**
     * Mark one notification read
     *
     * Responds `404` for a notification that is not this supplier's.
     */
    public function markRead(Request $request, string $notification, MarkNotificationsRead $markRead): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user();

        abort_unless($markRead->one($supplier, $notification), 404);

        return response()->noContent();
    }
}