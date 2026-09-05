<?php

namespace Database\Factories;

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BusinessAccount>
 *
 * The commercial lifecycle lives here, not on {@see UserFactory} (D23). A test
 * that wants an activated trader asks for an activated *account*; one that wants
 * a Feriwala staff member asks for a user and gives them no account at all.
 *
 * Every state a gate turns on has a name, because a default that quietly
 * satisfies the gate under test is how a broken gate passes its own suite.
 */
class BusinessAccountFactory extends Factory
{
    protected $model = BusinessAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'owner_id' => User::factory(),

            // An ordinary, usable business. Most features live past activation,
            // and the §5.4 funnel gate turns an unactivated account back — so a
            // default of `Registered` would mean nearly every test had to say
            // "and it is activated" before testing anything.
            'status' => AccountStatus::Active,
            'activated_at' => now(),
        ];
    }

    /** Fully activated and trading. */
    public function active(): static
    {
        return $this->state(fn () => [
            'status' => AccountStatus::Active,
            'activated_at' => now(),
        ]);
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

    /** Sent back to fix its evidence (§7.3). */
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

    public function configure(): static
    {
        return $this->afterMaking(function (BusinessAccount $account) {
            // Keep the pair honest. An account asked for as `KycPending` should
            // not silently inherit the default's `activated_at`, and having to
            // remember to null it at every call site is how that inconsistency
            // gets into the fixtures in the first place.
            if (! $account->status->isActivated()) {
                $account->activated_at = null;
            }

            if ($account->status !== AccountStatus::ApprovalPending) {
                $account->approval_pending_at = null;
            }
        })->afterCreating(function (BusinessAccount $account) {
            // The owner is a member with the Owner role, not a special case
            // beside the members table — so "who may work here" has one answer.
            $account->memberships()->create([
                'user_id' => $account->owner_id,
                'role' => TeamRole::Owner->value,
            ]);
        });
    }
}
