<?php

use App\Actions\Fortify\CreateNewUser;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Referral\ReferralCode;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registrationInput(array $overrides = []): array
{
    return [
        'name' => 'Nusrat Jahan',
        'email' => 'nusrat@example.test',
        'mobile' => '+8801712345678',
        // A long passphrase is not enough on its own: §6's policy asks for a
        // digit and a symbol as well, so the fixture has to carry them.
        'password' => testStrongPassword(),
        'password_confirmation' => testStrongPassword(),
        'terms_accepted' => '1',
        'privacy_accepted' => '1',
        ...$overrides,
    ];
}

function registerAccount(array $overrides = []): User
{
    return app(CreateNewUser::class)->create(registrationInput($overrides));
}

describe('creating the account', function () {
    it('starts a new account at Registered, not Active', function () {
        // §5.1: registration opens the account; activation needs verification,
        // KYC, payment, and administrative approval.
        expect(registerAccount()->businessAccount->status)->toBe(AccountStatus::Registered);
    });

    it('records the §5.2 details', function () {
        $user = registerAccount([
            'date_of_birth' => '1994-04-12',
            'gender' => 'female',
            'nationality' => 'Bangladeshi',
        ]);

        expect($user->mobile)->toBe('+8801712345678')
            ->and($user->date_of_birth->format('Y-m-d'))->toBe('1994-04-12')
            ->and($user->gender)->toBe('female')
            ->and($user->nationality)->toBe('Bangladeshi')
            ->and($user->country)->toBe('BD');
    });

    it('records both acceptances rather than assuming them', function () {
        $user = registerAccount();

        expect($user->terms_accepted_at)->not->toBeNull()
            ->and($user->privacy_accepted_at)->not->toBeNull();
    });

    it('gives the account a public id and no verified timestamps', function () {
        $user = registerAccount();

        expect($user->public_id)->not->toBeNull()
            ->and($user->email_verified_at)->toBeNull()
            ->and($user->mobile_verified_at)->toBeNull()
            ->and($user->isVerified())->toBeFalse()
            ->and($user->activated_at)->toBeNull();
    });
});

describe('acceptance is mandatory', function () {
    it('refuses registration without accepting the terms', function () {
        expect(fn () => registerAccount(['terms_accepted' => '0']))
            ->toThrow(ValidationException::class);
    });

    it('refuses registration without accepting the privacy policy', function () {
        expect(fn () => registerAccount(['privacy_accepted' => '0']))
            ->toThrow(ValidationException::class);
    });
});

describe('mobile', function () {
    it('is required', function () {
        $input = registrationInput();
        unset($input['mobile']);

        expect(fn () => app(CreateNewUser::class)->create($input))
            ->toThrow(ValidationException::class);
    });

    it('must be unique across accounts', function () {
        registerAccount();

        expect(fn () => registerAccount(['email' => 'someone-else@example.test']))
            ->toThrow(ValidationException::class);
    });
});

describe('referral capture (§25.1)', function () {
    it('issues every account its own referral code', function () {
        $user = registerAccount();

        expect($user->referral_code)->not->toBeNull()
            ->and(app(ReferralCode::class)->isWellFormed($user->referral_code))->toBeTrue();
    });

    it('gives two accounts different codes', function () {
        $first = registerAccount();
        $second = registerAccount([
            'email' => 'second@example.test',
            'mobile' => '+8801812345678',
        ]);

        expect($first->referral_code)->not->toBe($second->referral_code);
    });

    it('links a new account to an active referrer', function () {
        $referrer = tap(testBusinessAccount(AccountStatus::Active)->owner, fn ($u) => $u->forceFill(['referral_code' => 'K7M3QX9P'])->save());

        $user = registerAccount(['referral_code' => 'K7M3QX9P']);

        expect($user->referred_by_user_id)->toBe($referrer->id)
            ->and($user->referrer->is($referrer))->toBeTrue()
            ->and($referrer->referrals()->count())->toBe(1);
    });

    it('accepts a code typed in lower case or with spaces', function () {
        $referrer = tap(testBusinessAccount(AccountStatus::Active)->owner, fn ($u) => $u->forceFill(['referral_code' => 'K7M3QX9P'])->save());

        $user = registerAccount(['referral_code' => ' k7m3-qx9p ']);

        expect($user->referred_by_user_id)->toBe($referrer->id);
    });

    it('ignores a referral code belonging to an account that is not active', function () {
        // §25.1: only active users refer. A suspended account must not keep
        // earning rewards, or suspension means nothing.
        tap(testBusinessAccount(AccountStatus::Suspended)->owner, fn ($u) => $u->forceFill(['referral_code' => 'K7M3QX9P'])->save());

        expect(registerAccount(['referral_code' => 'K7M3QX9P'])->referred_by_user_id)
            ->toBeNull();
    });

    it('still registers the account when the code is wrong', function () {
        // A mistyped code must never block someone from registering.
        $user = registerAccount(['referral_code' => 'NOTACODE']);

        expect($user->exists)->toBeTrue()
            ->and($user->referred_by_user_id)->toBeNull();
    });

    it('registers without a referral code at all', function () {
        expect(registerAccount()->referred_by_user_id)->toBeNull();
    });
});
