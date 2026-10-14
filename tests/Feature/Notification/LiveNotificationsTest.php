<?php

use App\Domain\Notification\Events\NotificationsChanged;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Live notifications over Reverb (§33.2).
 *
 * The socket only nudges a person's own tabs; these tests hold the three things
 * that make that safe — every stored notification announces itself, only its
 * owner can listen or mark it read, and a dead socket server never costs the
 * notification.
 *
 * Helpers are prefixed because Pest loads every test file into one global
 * function namespace.
 */

function liveNotificationOwner(): User
{
    return User::factory()
        ->withBusinessAccount(fn ($account) => $account->active())
        ->create();
}

/**
 * A database-channel notification, defined inline so the test does not depend on
 * any one of the real notification classes.
 */
function liveNotificationSample(): Notification
{
    return new class extends Notification
    {
        /** @return array<int, string> */
        public function via(object $notifiable): array
        {
            return ['database'];
        }

        /** @return array<string, string> */
        public function toArray(object $notifiable): array
        {
            return ['event' => 'account.activated'];
        }
    };
}

/**
 * Switches the socket driver on for one test. The suite runs on the null driver,
 * which authorises every channel; the Reverb driver is the one that actually
 * asks the channel callbacks. Channels register on whichever driver was the
 * default when the routes loaded, so they are registered again on this one.
 */
function liveNotificationUseReverb(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);

    Broadcast::purge();
    require base_path('routes/channels.php');
}

it('announces a stored notification on its recipient\'s own private channel', function () {
    Event::fake([NotificationsChanged::class]);
    $user = liveNotificationOwner();

    $user->notify(liveNotificationSample());

    Event::assertDispatched(NotificationsChanged::class, fn (NotificationsChanged $event) => $event->channelName === 'notifications.user.'.$user->public_id
        && $event->reason === NotificationsChanged::RECEIVED
        && $event->unread === 1);
});

it('announces a Supplier\'s notification on the Supplier channel', function () {
    Event::fake([NotificationsChanged::class]);
    $supplier = Supplier::factory()->create();

    $supplier->notify(liveNotificationSample());

    Event::assertDispatched(NotificationsChanged::class, fn (NotificationsChanged $event) => $event->channelName === 'notifications.supplier.'.$supplier->public_id);
});

it('keeps the notification when the socket server is down', function () {
    $user = liveNotificationOwner();
    Event::listen(NotificationsChanged::class, fn () => throw new RuntimeException('Reverb is unreachable'));

    $user->notify(liveNotificationSample());

    expect($user->unreadNotifications()->count())->toBe(1);
});

it('marks one of your own notifications read and tells your other tabs', function () {
    $user = liveNotificationOwner();
    $user->notify(liveNotificationSample());
    $notification = $user->notifications()->firstOrFail();
    Event::fake([NotificationsChanged::class]);

    $this->actingAs($user)
        ->post(route('notifications.read', $notification->id))
        ->assertRedirect();

    expect($notification->fresh()->read_at)->not->toBeNull();
    Event::assertDispatched(NotificationsChanged::class, fn (NotificationsChanged $event) => $event->reason === NotificationsChanged::READ && $event->unread === 0);
});

it('answers a background mark-as-read without a redirect', function () {
    $user = liveNotificationOwner();
    $user->notify(liveNotificationSample());

    $this->actingAs($user)
        ->postJson(route('notifications.read', $user->notifications()->firstOrFail()->id))
        ->assertNoContent();
});

it('cannot mark someone else\'s notification read', function () {
    $owner = liveNotificationOwner();
    $owner->notify(liveNotificationSample());
    $notification = $owner->notifications()->firstOrFail();

    $this->actingAs(liveNotificationOwner())
        ->post(route('notifications.read', $notification->id))
        ->assertNotFound();

    expect($notification->fresh()->read_at)->toBeNull();
});

it('marks every unread notification read in one go', function () {
    $user = liveNotificationOwner();
    $user->notify(liveNotificationSample());
    $user->notify(liveNotificationSample());

    $this->actingAs($user)
        ->post(route('notifications.read-all'))
        ->assertRedirect();

    expect($user->unreadNotifications()->count())->toBe(0);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('unreadNotificationCount', 0));
});

it('does not touch another person\'s notifications when marking all read', function () {
    $other = liveNotificationOwner();
    $other->notify(liveNotificationSample());

    $this->actingAs(liveNotificationOwner())->post(route('notifications.read-all'));

    expect($other->unreadNotifications()->count())->toBe(1);
});

it('lets a Supplier mark their own notification read, and no one else\'s', function () {
    $supplier = Supplier::factory()->create();
    $supplier->notify(liveNotificationSample());
    $stranger = Supplier::factory()->create();
    $stranger->notify(liveNotificationSample());

    $this->actingAs($supplier, 'supplier')
        ->post(route('supplier.notifications.read-one', $stranger->notifications()->firstOrFail()->id))
        ->assertNotFound();

    $this->actingAs($supplier, 'supplier')
        ->post(route('supplier.notifications.read-one', $supplier->notifications()->firstOrFail()->id))
        ->assertRedirect();

    expect($supplier->unreadNotifications()->count())->toBe(0)
        ->and($stranger->unreadNotifications()->count())->toBe(1);
});

it('shares the signed-in person\'s own channel with the page', function () {
    $user = liveNotificationOwner();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('realtimeChannel', 'notifications.user.'.$user->public_id));
});

it('authorises a person onto their own channel and no other', function () {
    liveNotificationUseReverb();
    $user = liveNotificationOwner();
    $other = liveNotificationOwner();

    $authorise = fn (string $publicId) => $this->actingAs($user)->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-notifications.user.'.$publicId,
    ]);

    $authorise($user->public_id)->assertOk()->assertJsonStructure(['auth']);
    $authorise($other->public_id)->assertForbidden();
});

it('keeps a Client off a Supplier channel', function () {
    liveNotificationUseReverb();
    $supplier = Supplier::factory()->create();

    $this->actingAs(liveNotificationOwner())->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-notifications.supplier.'.$supplier->public_id,
    ])->assertForbidden();
});

it('refuses a guest a private channel', function () {
    liveNotificationUseReverb();

    $this->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-notifications.user.anyone',
    ])->assertForbidden();
});
