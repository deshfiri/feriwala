<?php

namespace Database\Factories;

use App\Domain\Account\Enums\AccountStatus;
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

            // An ordinary, usable account. Most features live past activation,
            // and the §5.4 funnel gate turns an unactivated account back — so a
            // default of `Registered` would mean nearly every test had to say
            // "and it is activated" before testing anything. Tests that care
            // about the funnel set the status they mean, explicitly.
            'status' => AccountStatus::Active,
            'activated_at' => now(),
            'mobile_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /*
     * Named states for every point of the lifecycle a gate can turn on.
     *
     * The default is an ordinary usable account, which suits the great majority
     * of tests. But a default that quietly satisfies an access gate is exactly
     * how a broken gate passes its own test suite — so activation, middleware,
     * policy and security tests must name the state they mean, and these exist
     * so naming it costs one word.
     */

    /** Fully activated and trading. */
    public function active(): static
    {
        return $this->state(fn () => [
            'status' => AccountStatus::Active,
            'activated_at' => now(),
        ]);
    }

    /**
     * A Feriwala staff account.
     *
     * Distinct from {@see active()} at the call site even though it produces the
     * same row today: staff and traders are different kinds of user, and the
     * separation of identity from business account will give them genuinely
     * different shapes.
     */
    public function staff(): static
    {
        return $this->active();
    }

    /**
     * Still inside the activation funnel (§5.4).
     *
     * @param  AccountStatus  $status  where in the funnel it sits
     */
    public function onboarding(AccountStatus $status = AccountStatus::Registered): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    /** Everything done, waiting on a human (§5.1, §44). */
    public function approvalPending(): static
    {
        return $this->onboarding(AccountStatus::ApprovalPending)
            ->state(fn () => ['approval_pending_at' => now()]);
    }

    /** Sent back to fix their evidence (§7.3). */
    public function kycResubmissionRequired(): static
    {
        return $this->onboarding(AccountStatus::KycResubmissionRequired);
    }

    /** Suspended by an administrator. Reversible (§5.3). */
    public function suspended(): static
    {
        return $this->onboarding(AccountStatus::Suspended);
    }

    /** Closed. Terminal; retention rules take over (D18). */
    public function closed(): static
    {
        return $this->onboarding(AccountStatus::Closed);
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
        return $this->afterMaking(function (User $user) {
            // Keep the pair honest. A test that asks for `KycPending` should not
            // silently inherit the default's `activated_at`, and having to
            // remember to null it at every call site is how that inconsistency
            // gets into the fixtures in the first place.
            if (! $user->status->isActivated()) {
                $user->activated_at = null;
            }

            // Likewise for the readiness stamp: only an account actually at the
            // gate has been waiting at it.
            if ($user->status !== AccountStatus::ApprovalPending) {
                $user->approval_pending_at = null;
            }
        })->afterCreating(function ($user) {
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
