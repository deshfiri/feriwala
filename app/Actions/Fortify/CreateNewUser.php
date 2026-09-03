<?php

namespace App\Actions\Fortify;

use App\Actions\Teams\CreateTeam;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\UserStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Referral\Actions\ResolveReferrer;
use App\Domain\Referral\ReferralCode;
use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Registers a Feriwala account (§5.1 step one, §5.2).
 *
 * Registration only opens the account — it does not activate it. The new account
 * starts at {@see AccountStatus::Registered} and must still verify, submit KYC,
 * choose a package, pay, and be approved before it can trade (§5.1, §44).
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(
        private CreateTeam $createTeam,
        private ReferralCode $referralCodes,
        private ResolveReferrer $resolveReferrer,
    ) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'mobile' => ['required', 'string', 'max:20', Rule::unique(User::class, 'mobile')],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],
            'nationality' => ['nullable', 'string', 'max:64'],
            'referral_code' => ['nullable', 'string', 'max:16'],

            // §5.2 requires both acceptances, so they are validated rather than
            // assumed from the presence of a form.
            'terms_accepted' => ['accepted'],
            'privacy_accepted' => ['accepted'],
        ], [
            'terms_accepted.accepted' => 'You must accept the terms and conditions.',
            'privacy_accepted.accepted' => 'You must accept the privacy policy.',
        ])->validate();

        return DB::transaction(function () use ($input) {
            $referrer = $this->resolveReferrer->handle($input['referral_code'] ?? null);

            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'mobile' => $input['mobile'],
                'password' => $input['password'],
                'date_of_birth' => $input['date_of_birth'] ?? null,
                'gender' => $input['gender'] ?? null,
                'country' => $input['country'] ?? 'BD',
                'nationality' => $input['nationality'] ?? null,
            ]);

            $user->forceFill([
                'identity_status' => UserStatus::Active,
                // Issued at registration so the code is stable for the life of
                // the account; it only becomes usable once the account is active
                // (§25.1), which ResolveReferrer enforces.
                'referral_code' => $this->referralCodes->generate(),
                'referred_by_user_id' => $referrer?->id,
                'terms_accepted_at' => now(),
                'privacy_accepted_at' => now(),
            ])->save();

            /*
             * Registering creates two things now (D23): the person, and the
             * business they are about to onboard. They are the owner, and the
             * commercial funnel — KYC, package, activation payment — runs
             * against the account rather than against them.
             *
             * Somebody Feriwala hires as platform staff gets no account, which
             * is why this belongs to registration rather than to User::create.
             */
            $account = BusinessAccount::create([
                'name' => $user->name,
                'owner_id' => $user->id,
                'status' => AccountStatus::Registered,
            ]);

            $account->memberships()->create([
                'user_id' => $user->id,
                'role' => TeamRole::Owner->value,
            ]);

            $this->createTeam->handle($user, $user->name."'s Team", isPersonal: true);

            return $user;
        });
    }
}
