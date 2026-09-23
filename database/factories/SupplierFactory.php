<?php

namespace Database\Factories;

use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    protected static ?string $password;

    /**
     * Defaults to an approved, fully verified Supplier — the usable case most
     * tests want. Tests exercising the funnel itself name the state they mean
     * ({@see draft()}, {@see underReview()}, ...), mirroring UserFactory's
     * convention.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_name' => fake()->company(),
            'contact_person_name' => fake()->name(),
            'business_address' => fake()->address(),
            'email' => fake()->unique()->safeEmail(),
            'mobile' => fake()->unique()->numerify('+88017########'),
            'password' => static::$password ??= Hash::make('password'),
            'email_verified_at' => now(),
            'mobile_verified_at' => now(),
            'status' => SupplierStatus::Approved,
            'submitted_at' => now(),
            'reviewed_at' => now(),
            'approved_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => SupplierStatus::Draft,
            'email_verified_at' => null,
            'mobile_verified_at' => null,
            'submitted_at' => null,
            'reviewed_at' => null,
            'approved_at' => null,
        ]);
    }

    public function verificationPending(): static
    {
        return $this->state(fn () => [
            'status' => SupplierStatus::VerificationPending,
            'email_verified_at' => null,
            'mobile_verified_at' => null,
            'reviewed_at' => null,
            'approved_at' => null,
        ]);
    }

    public function kycPending(): static
    {
        return $this->state(fn () => [
            'status' => SupplierStatus::KycPending,
            'reviewed_at' => null,
            'approved_at' => null,
        ]);
    }

    public function underReview(): static
    {
        return $this->state(fn () => [
            'status' => SupplierStatus::UnderReview,
            'approved_at' => null,
        ]);
    }

    public function correctionRequired(): static
    {
        return $this->state(fn () => [
            'status' => SupplierStatus::CorrectionRequired,
            'approved_at' => null,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => SupplierStatus::Rejected,
            'approved_at' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => SupplierStatus::Suspended,
            'suspended_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => SupplierStatus::Closed,
            'closed_at' => now(),
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => [
            'email_verified_at' => null,
            'mobile_verified_at' => null,
        ]);
    }
}
