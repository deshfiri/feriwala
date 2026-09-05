<?php

use App\Domain\Account\Models\AccountInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->withBusinessAccount(fn ($account) => $account->active())->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

test('the dashboard has one address with no account segment', function () {
    // D1 removes {current_team}. A dashboard URL that still carried an account
    // identifier would be one more thing to try somebody else's value in.
    expect(route('dashboard', absolute: false))->toBe('/dashboard');
});

test('the dashboard names the account without offering a list to switch between', function () {
    $user = User::factory()->withBusinessAccount(fn ($account) => $account->active())->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('account.name', $user->businessAccount->name)
            ->missing('teams')
            ->missing('currentTeam'),
        );
});

/**
 * Someone invited but not yet joined: a login with no membership, which is
 * exactly the state registration used to make impossible.
 *
 * @return array{0: User, 1: AccountInvitation}
 */
function dashboardInvitee(string $email = 'invited@example.com'): array
{
    $owner = User::factory()
        ->withBusinessAccount(fn ($account) => $account->active())
        ->create(['name' => 'Taylor Otwell']);

    $invitation = AccountInvitation::factory()->to($email)->create([
        'business_account_id' => $owner->businessAccount->id,
        'invited_by' => $owner->id,
    ]);

    return [User::factory()->create(['email' => $email]), $invitation];
}

describe('an invited person who has no account of their own', function () {
    it('is sent to the invitation rather than into a funnel that is not theirs', function () {
        // The §5.4 stepper belongs to a business being onboarded. An invitee is
        // not onboarding one — they are waiting to join somebody else's.
        [$invited, $invitation] = dashboardInvitee();

        $this->actingAs($invited)
            ->get(route('dashboard'))
            ->assertRedirect(route('staff.invitation.show', $invitation->token));
    });

    it('sees who invited them and to what', function () {
        [$invited, $invitation] = dashboardInvitee();

        $this->actingAs($invited)
            ->get(route('staff.invitation.show', $invitation->token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/invitation')
                ->where('invitation.invitedBy', 'Taylor Otwell')
                ->where('viewer.matches', true)
                ->where('viewer.hasAccount', false),
            );
    });

    it('is told plainly when the invitation is spent', function (array $spent) {
        [$invited, $invitation] = dashboardInvitee();
        $invitation->forceFill($spent)->save();

        $this->actingAs($invited)
            ->get(route('staff.invitation.show', $invitation->token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('invitation', null)
                ->whereNot('reason', null),
            );

        // Kept: the record of who was invited and what became of it survives
        // the invitation ceasing to be usable.
        $this->assertDatabaseHas('account_invitations', ['id' => $invitation->id]);
    })->with([
        'accepted' => fn () => ['accepted_at' => now()],
        'revoked' => fn () => ['revoked_at' => now()],
        'expired' => fn () => ['expires_at' => now()->subDay()],
    ]);

    it('cannot accept one addressed to somebody else', function () {
        [, $invitation] = dashboardInvitee('meant-for@example.com');
        $stranger = User::factory()->create(['email' => 'stranger@example.com']);

        $this->actingAs($stranger)
            ->get(route('staff.invitation.show', $invitation->token))
            ->assertInertia(fn (Assert $page) => $page->where('viewer.matches', false));

        $this->actingAs($stranger)
            ->from(route('staff.invitation.show', $invitation->token))
            ->post(route('staff.invitation.accept', $invitation->token))
            ->assertSessionHasErrors('invitation');
    });
});
