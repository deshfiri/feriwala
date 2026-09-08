<?php

use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\AccountInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('registration screen names the account an invitation came from', function () {
    $owner = User::factory()
        ->withBusinessAccount(fn ($account) => $account->active()->state(['name' => 'Karim Traders']))
        ->create();

    $invitation = AccountInvitation::factory()->create([
        'business_account_id' => $owner->businessAccount->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->get(route('register', ['invitation' => $invitation->token]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/register')
            ->where('staffInvitation.token', $invitation->token)
            ->where('staffInvitation.account', 'Karim Traders'),
        );
});

test('new users can register', function () {
    // Feriwala registration also requires a mobile number and explicit
    // acceptance of the terms and privacy policy (§5.2).
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'mobile' => '+8801712345678',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms_accepted' => '1',
        'privacy_accepted' => '1',
    ]);

    $this->assertAuthenticated();

    $user = User::where('email', 'test@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->businessAccount->status)->toBe(AccountStatus::Registered);

    $response->assertRedirect(route('dashboard'));
});

describe('the form and the validator agree', function () {
    it('hands the screen every list it has to offer', function () {
        /*
         * The failure this guards: the form asked for four fields while the
         * validator wanted seven, so every real registration failed on a mobile
         * number the form never collected. The pickers come from the same lists
         * the rules check against, or a form offers options that are refused.
         */
        $this->get(route('register'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/register')
                ->has('countries')
                ->has('genders', 4)
                ->where('defaultCountry', 'BD'),
            );
    });

    it('carries a referral code in from the link so it is not retyped', function () {
        $this->get(route('register', ['ref' => 'ABCD2345']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('referralCode', 'ABCD2345'),
            );
    });

    it('records the optional details when they are given', function () {
        $this->post(route('register.store'), [
            'name' => 'Karim Rahman',
            'email' => 'karim@example.com',
            'mobile' => '+8801712345601',
            'password' => 'password',
            'password_confirmation' => 'password',
            'date_of_birth' => '1990-04-12',
            'gender' => 'male',
            'country' => 'BD',
            'nationality' => 'Bangladeshi',
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
        ])->assertSessionHasNoErrors();

        $user = User::where('email', 'karim@example.com')->firstOrFail();

        expect($user->date_of_birth->toDateString())->toBe('1990-04-12')
            ->and($user->gender)->toBe('male')
            ->and($user->country)->toBe('BD')
            ->and($user->nationality)->toBe('Bangladeshi');
    });

    it('refuses a gender outside the offered set', function () {
        // Free text accumulates "M", "male", "Male " and "পুরুষ" for one answer,
        // and no report can group them afterwards.
        $this->from(route('register'))->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'gender@example.com',
            'mobile' => '+8801712345602',
            'password' => 'password',
            'password_confirmation' => 'password',
            'gender' => 'whatever they typed',
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
        ])->assertSessionHasErrors('gender');

        expect(User::where('email', 'gender@example.com')->exists())->toBeFalse();
    });

    it('refuses a country outside the supported registry', function () {
        // A country nobody can resolve is a scope rule that matches nobody.
        $this->from(route('register'))->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'country@example.com',
            'mobile' => '+8801712345603',
            'password' => 'password',
            'password_confirmation' => 'password',
            'country' => 'ZZ',
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
        ])->assertSessionHasErrors('country');
    });

    it('refuses a birth date in the future', function () {
        $this->from(route('register'))->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'dob@example.com',
            'mobile' => '+8801712345604',
            'password' => 'password',
            'password_confirmation' => 'password',
            'date_of_birth' => now()->addDay()->toDateString(),
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
        ])->assertSessionHasErrors('date_of_birth');
    });

    it('defaults the country rather than storing nothing', function () {
        // Every account is scoped by country somewhere — KYC requirements,
        // courier zones, tax. A null there is a row no rule can match.
        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'default@example.com',
            'mobile' => '+8801712345605',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
        ]);

        expect(User::where('email', 'default@example.com')->value('country'))->toBe('BD');
    });
});

test('registration is refused without accepting the terms and privacy policy', function () {
    $response = $this->from(route('register'))->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'mobile' => '+8801712345678',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors(['terms_accepted', 'privacy_accepted']);
    $this->assertGuest();

    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
});

describe('registering to accept an invitation', function () {
    it('joins the inviting account instead of opening a second one', function () {
        /*
         * The failure this guards is total: without it, registration gives the
         * invitee an account of their own, that account takes the one
         * membership D1 allows them, and the invitation that brought them here
         * is then refused for a conflict registration itself created. Nobody
         * could ever be invited.
         */
        // On a package with staff seats: an invitation cannot be taken up
        // against a package that grants none, which is the point of the
        // second case below it.
        $account = testAccountWithStaffLimit(5);

        $invitation = AccountInvitation::factory()
            ->to('joiner@example.com')
            ->role(AccountRole::Manager)
            ->create(['business_account_id' => $account->id]);

        $this->post(route('register.store'), [
            'name' => 'New Joiner',
            'email' => 'joiner@example.com',
            'mobile' => '+8801712345699',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
            'invitation' => $invitation->token,
        ]);

        $joiner = User::where('email', 'joiner@example.com')->firstOrFail();

        expect($joiner->businessAccount->id)->toBe($account->id)
            ->and($joiner->accountRole())->toBe(AccountRole::Manager)
            ->and($joiner->ownsBusinessAccount())->toBeFalse()
            ->and($invitation->refresh()->isLive())->toBeFalse();
    });

    it('is refused, not quietly turned into a new business, when the seat has gone', function () {
        // Someone who came here to join a business must not silently end up
        // owning a different one.
        $account = testAccountWithStaffLimit(1);
        User::factory()->staffOf($account)->create();

        $invitation = AccountInvitation::factory()
            ->to('late@example.com')
            ->create(['business_account_id' => $account->id]);

        $this->from(route('register'))->post(route('register.store'), [
            'name' => 'Late Joiner',
            'email' => 'late@example.com',
            'mobile' => '+8801712345677',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
            'invitation' => $invitation->token,
        ])->assertSessionHasErrors('invitation');

        expect(User::where('email', 'late@example.com')->exists())->toBeFalse();
    });

    it('opens an ordinary business when the token was addressed to somebody else', function () {
        // A forwarded link must not hand over access. It falls back to the
        // ordinary path rather than failing, because the person in front of us
        // is registering in good faith.
        $owner = User::factory()
            ->withBusinessAccount(fn ($account) => $account->active())
            ->create();

        $invitation = AccountInvitation::factory()
            ->to('meant-for@example.com')
            ->create(['business_account_id' => $owner->businessAccount->id]);

        $this->post(route('register.store'), [
            'name' => 'Someone Else',
            'email' => 'someone-else@example.com',
            'mobile' => '+8801712345688',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
            'invitation' => $invitation->token,
        ]);

        $other = User::where('email', 'someone-else@example.com')->firstOrFail();

        expect($other->ownsBusinessAccount())->toBeTrue()
            ->and($other->businessAccount->status)->toBe(AccountStatus::Registered)
            ->and($invitation->refresh()->isLive())->toBeTrue();
    });
});
