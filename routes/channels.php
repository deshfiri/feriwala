<?php

use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Notification channels (§33.2). One private channel per person, named by public
 * id — the name is visible in the browser, so it never carries a row id.
 *
 * A Supplier is a separate identity on its own guard (D25), so each channel is
 * pinned to the one guard that may open it: a Client's session cannot subscribe
 * to a Supplier's channel or the reverse, even with a guessed public id.
 */
Broadcast::channel('notifications.user.{publicId}', function (User $user, string $publicId): bool {
    return hash_equals($user->public_id, $publicId);
}, ['guards' => ['web']]);

Broadcast::channel('notifications.supplier.{publicId}', function (Supplier $supplier, string $publicId): bool {
    return hash_equals($supplier->public_id, $publicId);
}, ['guards' => ['supplier']]);
