<?php

use App\Domain\Notification\Queries\RecentNotifications;
use App\Models\User;
use App\Notifications\Account\AccountActivated;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The header bell (§33.2, D20).
 *
 * The stored notification keeps an `event` plus that event's own fields, and no
 * two events agree on a shape — so what these tests really hold is the
 * presenter that turns seven different payloads into one readable row.
 *
 * Helpers are prefixed because Pest loads every test file into one global
 * function namespace.
 */

function headerNotificationOwner(): User
{
    return User::factory()
        ->withBusinessAccount(fn ($account) => $account->active())
        ->create();
}

/**
 * A stored database notification with `$data` as its payload.
 *
 * Written through the relationship rather than through a notification class, so
 * a test can pose an event the application does not send yet — including one it
 * has never heard of.
 *
 * @param  array<string, mixed>  $data
 */
function headerNotificationRow(
    User $user,
    array $data,
    ?string $readAt = null,
    int $minutesAgo = 0,
): void {
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'test.event',
        'data' => $data,
        'read_at' => $readAt,
        'created_at' => now()->subMinutes($minutesAgo),
        'updated_at' => now()->subMinutes($minutesAgo),
    ]);
}

it('reads a stored event as a sentence, not as an event name', function () {
    $user = headerNotificationOwner();
    headerNotificationRow($user, ['event' => 'account.activated', 'note' => null]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications.0.title', 'Your account is now active')
            ->where('notifications.0.description', 'You now have full access to your Feriwala account.')
            ->where('unreadNotificationCount', 1),
        );
});

it('prefers what an administrator actually wrote over the generic line', function () {
    $user = headerNotificationOwner();

    headerNotificationRow($user, [
        'event' => 'kyc.update_requested',
        'instructions' => 'Your trade licence photo is cut off at the bottom.',
    ]);

    // Their words say what to do about it; the generic line only says what
    // happened.
    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where(
                'notifications.0.description',
                'Your trade licence photo is cut off at the bottom.',
            ),
        );
});

it('gives a notification somewhere to go only when there is somewhere', function () {
    $user = headerNotificationOwner();

    headerNotificationRow($user, ['event' => 'kyc.deadline_missed'], minutesAgo: 10);
    headerNotificationRow($user, ['event' => 'account.activated'], minutesAgo: 1);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            // Newest first, so the activation notice is row zero. It is news,
            // not a task, and a row that looks clickable but goes nowhere is
            // worse than plain text.
            ->where('notifications.0.href', null)
            // The overdue one has somewhere to go, so it is a link.
            ->where('notifications.1.href', route('kyc.history')),
        );
});

it('shows an event it does not recognise rather than dropping it', function () {
    $user = headerNotificationOwner();
    headerNotificationRow($user, ['event' => 'wallet.topped_up']);

    // A visible `wallet.topped_up` in the bell is an obvious bug someone fixes.
    // A row that silently vanishes is one nobody notices — and it was a real
    // notification somebody was meant to read.
    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('notifications', 1)
            ->where('notifications.0.title', 'wallet.topped_up'),
        );
});

it('counts unread rather than deriving it from the capped list', function () {
    $user = headerNotificationOwner();

    foreach (range(1, RecentNotifications::LIMIT + 4) as $ignored) {
        headerNotificationRow($user, ['event' => 'account.activated']);
    }

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            // The menu shows ten; the badge must still say fourteen.
            ->has('notifications', RecentNotifications::LIMIT)
            ->where('unreadNotificationCount', RecentNotifications::LIMIT + 4),
        );
});

it('leaves a read notification out of the count but not out of the list', function () {
    $user = headerNotificationOwner();

    headerNotificationRow($user, ['event' => 'account.activated'], readAt: now()->toDateTimeString());

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('notifications', 1)
            ->whereNot('notifications.0.readAt', null)
            ->where('unreadNotificationCount', 0),
        );
});

it('never shows one person the notifications of another', function () {
    $mine = headerNotificationOwner();
    $theirs = headerNotificationOwner();

    headerNotificationRow($theirs, ['event' => 'account.suspended']);

    // Scoped by the relationship itself, which is what keeps §31.3 honest here:
    // there is no account or user parameter for anyone to substitute.
    $this->actingAs($mine)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications', [])
            ->where('unreadNotificationCount', 0),
        );
});

it('reads in the language the person chose', function () {
    $user = headerNotificationOwner();
    headerNotificationRow($user, ['event' => 'account.activated']);

    // The wording lives in the lang files, not in the stored row — which is
    // what lets a notification sent in English read in Bangla later (D6).
    $this->actingAs($user)
        ->withSession(['locale' => 'bn'])
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications.0.title', 'আপনার অ্যাকাউন্ট এখন সচল'),
        );
});

it('is empty for someone who has had none, without inventing a badge', function () {
    Notification::fake();

    $this->actingAs(headerNotificationOwner())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications', [])
            ->where('unreadNotificationCount', 0),
        );

    Notification::assertNothingSent();
});

it('carries a real notification end to end', function () {
    $user = headerNotificationOwner();

    // Not a hand-written row: the real class, through the real database
    // channel, so a change to its payload shows up here.
    $user->notify(new AccountActivated('Welcome aboard.'));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications.0.title', 'Your account is now active')
            ->where('notifications.0.description', 'Welcome aboard.')
            ->where('unreadNotificationCount', 1),
        );
});
