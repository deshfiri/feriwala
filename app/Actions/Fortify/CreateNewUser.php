<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Domain\Account\Actions\AcceptStaffInvitation;
use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\UserStatus;
use App\Domain\Account\Exceptions\StaffLimitReached;
use App\Domain\Account\Models\AccountInvitation;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Referral\Actions\ResolveReferrer;
use App\Domain\Referral\ReferralCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
        private AcceptStaffInvitation $acceptStaffInvitation,
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

            // Carried through from the invitation link. Not validated against
            // the database here: an unusable token means "register normally",
            // not "refuse the registration".
            'invitation' => ['nullable', 'string', 'max:64'],

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
             * Somebody registering to accept an invitation is joining a business
             * that already exists, so no second one is opened for them (D1, D23).
             *
             * Without this they would leave registration owning an account and
             * therefore holding the one membership D1 allows — and the
             * invitation that brought them here would be unacceptable, refused
             * for a conflict registration itself had just created.
             */
            $invitation = $this->invitationFor($input, $user);

            if ($invitation !== null) {
                try {
                    $this->acceptStaffInvitation->handle($invitation, $user);
                } catch (StaffLimitReached $exception) {
                    // The seat went while they were filling in the form. Said
                    // plainly rather than quietly opening a business of their
                    // own, which is not what they came here to do.
                    throw ValidationException::withMessages([
                        'invitation' => $exception->getMessage(),
                    ]);
                }

                return $user;
            }

            /*
             * Otherwise registering creates two things (D23): the person, and
             * the business they are about to onboard. They are the owner, and
             * the commercial funnel — KYC, package, activation payment — runs
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
                'role' => AccountRole::Owner->value,
            ]);

            return $user;
        });
    }

    /**
     * The invitation this registration is answering, if it is answering one.
     *
     * The token is not enough on its own: {@see AccountInvitation::matches()}
     * still has to agree that this is the person it was addressed to, so
     * registering with somebody else's link produces an ordinary new business
     * rather than access to theirs.
     *
     * @param  array<string, string>  $input
     */
    private function invitationFor(array $input, User $user): ?AccountInvitation
    {
        $token = $input['invitation'] ?? null;

        if (! is_string($token) || $token === '') {
            return null;
        }

        $invitation = AccountInvitation::query()->where('token', $token)->live()->first();

        return $invitation?->matches($user) === true ? $invitation : null;
    }
}
