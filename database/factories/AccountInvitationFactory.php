<?php

namespace Database\Factories;

use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Models\AccountInvitation;
use App\Domain\Account\Models\BusinessAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccountInvitation>
 */
class AccountInvitationFactory extends Factory
{
    protected $model = AccountInvitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_account_id' => BusinessAccount::factory(),
            'email' => fake()->unique()->safeEmail(),
            'mobile' => null,
            'role' => AccountRole::Staff,
            'token' => Str::random(64),
            'expires_at' => now()->addDays(14),
        ];
    }

    /** Already used. Stops being live, releases its seat. */
    public function accepted(): static
    {
        return $this->state(fn () => ['accepted_at' => now()]);
    }

    /** Withdrawn before use. */
    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }

    /** Past its window. */
    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function role(AccountRole $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }

    public function to(string $email, ?string $mobile = null): static
    {
        return $this->state(fn () => [
            'email' => mb_strtolower($email),
            'mobile' => $mobile,
        ]);
    }

    public function configure(): static
    {
        return $this->afterMaking(function (AccountInvitation $invitation) {
            // Default the inviter to the account's owner. `invited_by` is not
            // nullable — an invitation nobody sent is not a thing — and making
            // every call site say so would only produce copies of this line.
            if (blank($invitation->getAttribute('invited_by'))) {
                $invitation->invited_by = BusinessAccount::query()
                    ->whereKey($invitation->business_account_id)
                    ->value('owner_id');
            }
        });
    }
}
