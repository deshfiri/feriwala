<?php

namespace App\Domain\Order\Data;

use App\Domain\Order\Enums\ReturnCaseType;
use App\Domain\Order\Enums\ReturnInitiator;
use App\Domain\Order\Enums\ReturnParty;
use App\Domain\Order\Enums\ReturnReason;

/**
 * A return or an exchange as somebody asks for it — a customer through their
 * shop, a Partner, or staff (contract §6.3, P6-12, D35).
 *
 * A claim, not a decision. Everything in it is checked against the order before
 * anything is written: which lines exist, how many are left to send back, and
 * whether the order is in a state that allows any of it.
 *
 * Who is asking and who is responsible are **separate**. A Partner's request is
 * the Partner's and begins as the Partner's responsibility; it can never begin
 * as Banij's. Only a member of staff states who is responsible, and may open a
 * case on behalf of either party. Left unsaid, both are derived from where the
 * request came from — see {@see RequestOrderReturn}.
 */
final class ReturnSubmission
{
    /**
     * @param  array<int, array{sku: string, quantity: int}>  $lines  what is coming back
     * @param  array<int, string>|null  $evidence  references the asker supplied, never files
     * @param  array<string, string>  $replacements  an exchange's replacement, by the SKU it replaces (an absent entry means the same unit)
     */
    public function __construct(
        public readonly ReturnReason $reason,
        public readonly array $lines,
        public readonly ?string $customerNote = null,
        public readonly ?array $evidence = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ReturnCaseType $caseType = ReturnCaseType::Return,
        public readonly ?ReturnInitiator $initiator = null,
        public readonly ?ReturnParty $onBehalfOf = null,
        public readonly ?ReturnParty $responsibleParty = null,
        public readonly array $replacements = [],
    ) {}

    /**
     * The quantities asked for, by SKU, with anything unreadable dropped.
     *
     * @return array<string, int>
     */
    public function quantities(): array
    {
        $quantities = [];

        foreach ($this->lines as $line) {
            $sku = trim($line['sku']);
            $quantity = $line['quantity'];

            if ($sku === '' || $quantity <= 0) {
                continue;
            }

            $quantities[$sku] = ($quantities[$sku] ?? 0) + $quantity;
        }

        return $quantities;
    }
}
