<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\UserStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Referral\Actions\AttachReferrer;
use App\Domain\Referral\Actions\ResolveReferrer;
use App\Domain\Referral\ReferralCode;
use App\Models\User;
use App\Support\Localization\Countries;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Opens a Client/Partner account on someone's behalf (staff-created).
 *
 * Creates exactly what self-registration creates -- the person, the business
 * they own, the owner membership, and the referrer attachment -- through the
 * same models and actions, so nothing downstream can tell the two apart except
 * the audit trail. The business starts at {@see AccountStatus::Registered} and
 * walks the same onboarding funnel; staff then verify and activate it through
 * the existing, separately-audited actions.
 *
 * **No password is ever chosen or shown.** The identity is created with a
 * random secret nobody sees, and the owner receives a password-setup link
 * ({@see ManagePasswordSetup}). Email and mobile start unverified -- opening
 * an account is not evidence the person owns them.
 */
class CreateManagedAccount
{
    public function __construct(
        protected DatabaseManager $database,
        protected ReferralCode $referralCodes,
        protected ResolveReferrer $resolveReferrer,
        protected AttachReferrer $attachReferrer,
        protected ManagePasswordSetup $passwordSetup,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input  name, business_name, email, mobile, reason; optionally country, referral_code
     *
     * @throws ValidationException
     */
    public function handle(User $actor, array $input): BusinessAccount
    {
        $data = validator($input, [
            'name' => ['required', 'string', 'max:255'],
            'business_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'mobile' => ['required', 'string', 'max:20', Rule::unique(User::class, 'mobile')],
            'country' => ['nullable', Rule::in(app(Countries::class)->codes())],
            'referral_code' => ['nullable', 'string', 'max:16'],
            'reason' => ['required', 'string', 'max:1000'],
        ])->validate();

        $account = $this->database->transaction(function () use ($actor, $data) {
            $referrer = $this->resolveReferrer->handle($data['referral_code'] ?? null);

            if (filled($data['referral_code'] ?? null) && $referrer === null) {
                throw ValidationException::withMessages([
                    'referral_code' => 'That partner code does not belong to an active account.',
                ]);
            }

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'mobile' => $data['mobile'],
                // A secret nobody sees; the owner sets their own below.
                'password' => Str::password(40),
                'country' => $data['country'] ?? app(Countries::class)->default(),
            ]);

            $user->forceFill([
                'identity_status' => UserStatus::Active,
                'referral_code' => $this->referralCodes->generate(),
                'referred_by_user_id' => $referrer?->id,
            ])->save();

            $account = BusinessAccount::create([
                'name' => $data['business_name'],
                'owner_id' => $user->id,
                'status' => AccountStatus::Registered,
            ]);

            $account->memberships()->create([
                'user_id' => $user->id,
                'role' => AccountRole::Owner->value,
            ]);

            $referrerAccount = $referrer?->ownedAccount;

            if ($referrer !== null && $referrerAccount !== null) {
                $this->attachReferrer->atRegistration($account, $referrerAccount, $referrer->referral_code);
            }

            $this->audit->handle(new AuditEntry(
                action: 'account.created_by_staff',
                actorId: $actor->id,
                auditableType: BusinessAccount::class,
                auditableId: $account->id,
                accountId: $account->id,
                after: ['name' => $account->name, 'status' => $account->status->value, 'owner' => $user->public_id ?? $user->id],
                reason: $data['reason'],
                module: 'account',
                isSensitive: true,
            ));

            return $account;
        });

        $this->passwordSetup->issue($account->owner()->firstOrFail(), ManagePasswordSetup::USERS, $actor, 'Account opened by staff: '.$data['reason']);

        return $account;
    }
}
