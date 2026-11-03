<?php

namespace App\Domain\Order\Data;

use App\Domain\Order\Models\OrderReceivableCollection;
use Carbon\CarbonImmutable;

/**
 * Where Banij's receivable on one Partner-gateway order stands, in the form the
 * screens and APIs read it (D36).
 *
 * The backend-facing payload the Admin and Partner screens are built on later:
 * it distinguishes waiting for the Wallet, the Wallet falling short with its
 * deadline, stock that has to be recovered by a person, a collected receivable,
 * and a deadline that ran out with the customer's payment still to be refunded.
 * It carries no customer detail — only the order's own reference and the money
 * Banij is owed.
 */
final readonly class ReceivableCollectionState
{
    /**
     * @param  array<string, mixed>  $amount  `Money::jsonSerialize()` of the frozen receivable
     */
    public function __construct(
        public string $id,
        public string $status,
        public string $stage,
        public ?string $reason,
        public array $amount,
        public CarbonImmutable $verifiedAt,
        public CarbonImmutable $deadlineAt,
        public ?CarbonImmutable $collectedAt,
        public bool $blocksWithdrawals,
        public bool $needsStaff,
    ) {}

    public static function of(OrderReceivableCollection $collection, ?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();
        $status = $collection->status;

        return new self(
            id: $collection->public_id,
            status: $status->value,
            stage: $status->stage(),
            reason: $collection->status_reason,
            amount: $collection->amount->jsonSerialize(),
            verifiedAt: $collection->verified_at,
            deadlineAt: $collection->deadline_at,
            collectedAt: $collection->collected_at,
            blocksWithdrawals: $status->isActive() && ! $collection->isPastDeadline($now),
            needsStaff: in_array($status->stage(), ['stock_recovery_required', 'expired_refund_required'], true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'stage' => $this->stage,
            'reason' => $this->reason,
            'amount' => $this->amount,
            'verified_at' => $this->verifiedAt->toIso8601String(),
            'deadline_at' => $this->deadlineAt->toIso8601String(),
            'collected_at' => $this->collectedAt?->toIso8601String(),
            'blocks_withdrawals' => $this->blocksWithdrawals,
            'needs_staff' => $this->needsStaff,
        ];
    }
}
