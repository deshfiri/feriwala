<?php

use App\Models\User;
use App\Support\Security\PasswordPolicy;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Validation\Rules\Password;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The strong-password policy (P1-12, §6).
 *
 * One definition, applied by every flow that writes a password. The failure
 * this guards against is not a weak rule — it is three flows quietly disagreeing
 * about what the rule is, which nobody notices because each is tested alone.
 */

/**
 * A password that satisfies none of the policy beyond existing.
 *
 * Prefixed because Pest loads every test file into one global function
 * namespace, where a bare `policyWeakPassword()` would collide with the next file
 * that wants one.
 */
function policyWeakPassword(): string
{
    return 'password';
}

describe('the policy itself', function () {
    it('applies in this environment, not only in production', function () {
        /*
         * The defect this exists for. `Password::defaults()` returned null
         * outside production, and Laravel then falls back to eight characters
         * and nothing else — so development, staging and the whole test suite
         * ran a policy nobody had chosen, and the real one was never exercised
         * anywhere it could be observed.
         */
        $rules = Password::default();

        expect($rules)->toBeInstanceOf(Password::class);

        // Read from the rule object rather than restated, so a change to the
        // policy cannot leave this assertion describing the old one.
        $hint = PasswordPolicy::hint();

        expect($hint)->toContain('minlength: '.PasswordPolicy::MINIMUM_LENGTH)
            ->and($hint)->toContain('required: lower')
            ->and($hint)->toContain('required: upper')
            ->and($hint)->toContain('required: digit')
            ->and($hint)->toContain('required: special');
    });

    it('asks for more than the framework default', function () {
        // Eight characters is Laravel's fallback, and §6 asks for strong.
        expect(PasswordPolicy::MINIMUM_LENGTH)->toBeGreaterThan(8);
    });
});

describe('every flow that writes a password', function () {
    it('refuses a weak one at registration', function () {
        $this->from(route('register'))
            ->post(route('register.store'), [
                'name' => 'Policy Test',
                'email' => 'weak@example.com',
                'mobile' => '+8801712340001',
                'password' => policyWeakPassword(),
                'password_confirmation' => policyWeakPassword(),
                'terms_accepted' => '1',
                'privacy_accepted' => '1',
            ])
            ->assertSessionHasErrors('password');

        expect(User::where('email', 'weak@example.com')->exists())->toBeFalse();
    });

    it('accepts a strong one at registration', function () {
        $this->post(route('register.store'), [
            'name' => 'Policy Test',
            'email' => 'strong@example.com',
            'mobile' => '+8801712340002',
            'password' => testStrongPassword(),
            'password_confirmation' => testStrongPassword(),
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
        ])->assertSessionHasNoErrors();

        expect(User::where('email', 'strong@example.com')->exists())->toBeTrue();
    });

    it('refuses a weak one at password reset', function () {
        $user = User::factory()->create();
        $token = PasswordBroker::createToken($user);
        $before = $user->password;

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => policyWeakPassword(),
            'password_confirmation' => policyWeakPassword(),
        ])->assertSessionHasErrors('password');

        // Unchanged, rather than "is not the weak one": the factory's password
        // is that same string, so checking it would pass however the reset went.
        expect($user->refresh()->password)->toBe($before);
    });

    it('accepts a strong one at password reset', function () {
        $user = User::factory()->create();
        $token = PasswordBroker::createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => testStrongPassword(),
            'password_confirmation' => testStrongPassword(),
        ])->assertSessionHasNoErrors();

        expect(Hash::check(testStrongPassword(), $user->refresh()->password))->toBeTrue();
    });

    it('refuses a weak one when signed in and changing it', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('security.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => policyWeakPassword(),
                'password_confirmation' => policyWeakPassword(),
            ])
            ->assertSessionHasErrors('password');
    });

    it('accepts a strong one when signed in and changing it', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => testStrongPassword(),
                'password_confirmation' => testStrongPassword(),
            ])
            ->assertSessionHasNoErrors();

        expect(Hash::check(testStrongPassword(), $user->refresh()->password))->toBeTrue();
    });
});

describe('what the screens are told', function () {
    it('gives every password screen the same policy the validator applies', function () {
        /*
         * All three declared the prop; only the settings screen was given it,
         * so the two flows where a password is *first* chosen had no guidance
         * at all. A password manager reads this to generate something that will
         * be accepted — a hint that disagrees with the validator sends people to
         * a generated password the form then rejects.
         */
        $hint = PasswordPolicy::hint();

        $this->get(route('register'))
            ->assertInertia(fn (Assert $page) => $page->where('passwordRules', $hint));

        $user = User::factory()->create();
        $token = PasswordBroker::createToken($user);

        $this->get(route('password.reset', $token))
            ->assertInertia(fn (Assert $page) => $page->where('passwordRules', $hint));

        // The security screen sits behind a password confirmation, so the
        // session needs one before it renders rather than redirects.
        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('passwordRules', $hint));
    });
});

describe('how it is stored', function () {
    it('never keeps the plaintext', function () {
        $this->post(route('register.store'), [
            'name' => 'Hash Test',
            'email' => 'hash@example.com',
            'mobile' => '+8801712340003',
            'password' => testStrongPassword(),
            'password_confirmation' => testStrongPassword(),
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
        ]);

        $stored = User::where('email', 'hash@example.com')->value('password');

        expect($stored)->not->toBe(testStrongPassword())
            ->and($stored)->toStartWith('$2y$')
            ->and(Hash::check(testStrongPassword(), $stored))->toBeTrue();
    });

    it('keeps the password out of anything the response hands back', function () {
        // §42: a plaintext password must not reach a log, an audit row or a
        // rendered page. The shared Inertia props are the widest surface.
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'));

        /*
         * The word "password" is legitimately all over this page — it is a
         * field name. What must never appear is the credential itself: the
         * stored hash, or the plaintext the factory used to make it.
         */
        expect($response->getContent())->not->toContain($user->password)
            ->and($response->getContent())->not->toContain(testStrongPassword());
    });

    it('re-hashes an out-of-date hash at the next sign-in rather than in bulk', function () {
        /*
         * §6. The plaintext needed to re-hash only exists during a login, so a
         * bulk migration is not merely undesirable — it is impossible. Raising
         * the cost has to migrate the store one sign-in at a time.
         */
        expect(config('hashing.rehash_on_login'))->toBeTrue();

        $user = User::factory()->create([
            'password' => Hash::driver('bcrypt')->make('password', ['rounds' => 4]),
        ]);

        $before = $user->password;

        /*
         * The suite runs bcrypt at 4 rounds for speed (phpunit.xml), so a
         * 4-round hash is already current here. Raising the configured cost is
         * what makes the stored one stale — which is the situation this is
         * about: the cost went up, and the store has to catch up by itself.
         */
        Hash::driver('bcrypt')->setRounds(6);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);

        expect($user->refresh()->password)->not->toBe($before)
            ->and(Hash::check('password', $user->password))->toBeTrue();
    });
});
