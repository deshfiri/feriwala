<?php

namespace App\Domain\Payout\Data;

/**
 * What a withdrawal freezes onto itself at request time — everything a
 * later screen or staff review needs, never the raw encrypted account
 * number.
 */
readonly class PayoutMethodSnapshot
{
    public function __construct(
        public string $payoutMethodId,
        public string $type,
        public string $typeLabel,
        public string $label,
        public string $maskedNumber,
        public ?string $bankName = null,
        public ?string $branchName = null,
        public ?string $district = null,
        public ?string $routingNumber = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'payout_method_id' => $this->payoutMethodId,
            'type' => $this->type,
            'type_label' => $this->typeLabel,
            'label' => $this->label,
            'masked_number' => $this->maskedNumber,
            'bank_name' => $this->bankName,
            'branch_name' => $this->branchName,
            // Absent on every snapshot written before this field existed --
            // never backfilled (§27's own "never rewrite" rule), so a staff
            // screen reading an old withdrawal's raw payout_snapshot finds no
            // key here at all rather than a null one. Screens must treat
            // "missing" and "null" the same way (§7.4-equivalent: read
            // defensively rather than assume every row was written by the
            // current shape).
            'district' => $this->district,
            'routing_number' => $this->routingNumber,
        ];
    }
}
