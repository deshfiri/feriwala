<?php

namespace Database\Factories;

use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\Refundability;
use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Billing\Models\RefundRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RefundRequest>
 */
class RefundRequestFactory extends Factory
{
    protected $model = RefundRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'allocation_type' => AllocationType::PackageFee,
            'amount_minor' => 500000,
            'currency_code' => 'BDT',
            'refundability' => Refundability::BeforeActivation,
            'status' => RefundStatus::Requested,
            'reason' => 'Changed their mind before activation.',
        ];
    }

    public function status(RefundStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'decided_at' => $status->isDecided() ? now() : null,
        ]);
    }

    public function forType(AllocationType $type): static
    {
        return $this->state(fn () => ['allocation_type' => $type]);
    }
}
