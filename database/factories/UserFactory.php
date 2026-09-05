<?php

namespace Database\Factories;

use App\Domain\Account\Enums\UserStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),

            // §5.2 makes mobile a required, unique identifier, and anything
            // that sends an SMS silently does nothing without one — so the
            // default has to have it, or those paths never get exercised.
            'mobile' => fake()->unique()->numerify('+88017########'),

            // An ordinary, usable login. The commercial lifecycle is not here
            // any more (D23) — a user with no business account is a perfectly
            // valid Feriwala staff member.
            'identity_status' => UserStatus::Active,
            'mobile_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /*
     * Identity states (§6, D23). The commercial lifecycle — onboarding,
     * approval-pending, activated, suspended-as-a-business — lives on
     * {@see BusinessAccountFactory}, because those are facts about a business
     * rather than about a person.
     *
     * A default that quietly satisfies an access gate is how a broken gate
     * passes its own test suite, so security, middleware and policy tests must
     * name the state they mean. These exist so naming it costs one word.
     */

    /**
     * A Feriwala staff account: a login with **no business account**.
     *
     * The point of D23 in one factory state — administering the platform takes
     * an identity and a permission, not commercial KYC and an activation fee.
     */
    public function staff(): static
    {
        return $this->state(fn () => ['identity_status' => UserStatus::Active]);
    }

    /** Locked by a security control rather than a person (§6). */
    public function locked(): static
    {
        return $this->identity(UserStatus::Locked);
    }

    /** Suspended by an administrator: no access anywhere, admin included. */
    public function suspendedIdentity(): static
    {
        return $this->identity(UserStatus::Suspended);
    }

    /** Closed. Terminal; retention rules take over (D18). */
    public function closedIdentity(): static
    {
        return $this->identity(UserStatus::Closed);
    }

    public function identity(UserStatus $status): static
    {
        return $this->state(fn () => [
            'identity_status' => $status,
            'identity_status_changed_at' => now(),
        ]);
    }

    /**
     * Someone who owns a business account.
     *
     * The commercial state is the account's, so it is named there:
     * `withBusinessAccount(fn ($f) => $f->approvalPending())`.
     *
     * @param  (callable(BusinessAccountFactory): BusinessAccountFactory)|null  $state
     */
    public function withBusinessAccount(?callable $state = null): static
    {
        return $this->afterCreating(function (User $user) use ($state) {
            $factory = BusinessAccount::factory()->for($user, 'owner');

            ($state ? $state($factory) : $factory)->create([
                'name' => $user->name."'s Business",
            ]);
        });
    }

    /**
     * Neither email nor mobile confirmed.
     *
     * The starter kit's `unverified()` covers email only; §5.1 needs both, and
     * an account missing just the mobile still fails activation.
     */
    public function fullyUnverified(): static
    {
        return $this->state(fn () => [
            'email_verified_at' => null,
            'mobile_verified_at' => null,
        ]);
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function ($user) {
            $team = Team::factory()->personal()->create([
                'name' => $user->name."'s Team",
            ]);

            $team->members()->attach($user, [
                'role' => TeamRole::Owner->value,
            ]);

            $user->switchTeam($team);
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
