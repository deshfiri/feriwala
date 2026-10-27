<?php

namespace App\Domain\Cases\Queries;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Damage\Models\DamageCharge;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Order\Enums\ReturnChargeStatus;
use App\Domain\Order\Enums\ReturnParty;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Models\ReturnCharge;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * What a case costs and who bears it, as a screen shows it (D35).
 *
 * **Every figure comes from here, already worked out.** The summary rows, the
 * totals and the ledger rows are the server's own — exact decimal money from the
 * case's frozen snapshots and its charge records — and a screen only displays
 * them. Nothing is added, multiplied or compared in the browser.
 *
 * Two audiences, one source:
 *
 *   - **Staff** see every charge, who bears it, its state, the reason, any
 *     reversal or correction, and a link to the wallet transaction when they may
 *     open it.
 *   - **The Partner** sees only their own financial effects: what was charged to
 *     them, what is still owed, what was given back, and — where Banij bears a
 *     cost — that it was covered, with no amount of Banij's accounting.
 *
 * A charge reversed by a correction stays on the list, marked, beside the
 * entry that answers it; the totals count only what stands.
 */
class CaseFinancials
{
    /**
     * @return array<string, mixed>
     */
    public function forReturn(OrderReturn $case, ?User $staff = null): array
    {
        $case->loadMissing(['charges.walletTransaction.wallet', 'order']);

        $currency = Currency::from($case->currency_code);
        $forPartner = $staff === null;
        $bearer = $case->responsible_party;

        $summary = [
            $this->row('original_delivery', $case->original_delivery_charge, 'neutral', note: 'cases.financials.original_delivery_note'),
            $this->row('return_charge', $case->return_charge, 'debit', bearer: $bearer),
        ];

        if ($case->isExchange()) {
            $summary[] = $this->row('exchange_delivery', $case->exchange_delivery_charge, 'debit', bearer: $bearer);

            if ($case->exchange_additional_due?->isPositive() === true) {
                $summary[] = $this->row('exchange_additional_due', $case->exchange_additional_due, 'neutral', note: 'cases.financials.collected_from_customer');
            }

            if ($case->exchange_refund_due?->isPositive() === true) {
                $summary[] = $this->row('exchange_refund_due', $case->exchange_refund_due, 'credit', note: 'cases.financials.owed_to_customer');
            }
        } else {
            $summary[] = $this->row('product_refund', $case->refund_amount, 'credit', note: 'cases.financials.refund_note');
        }

        if ($case->other_adjustments?->isPositive() === true) {
            $summary[] = $this->row('other_adjustments', $case->other_adjustments, 'credit', note: $case->adjustment_note);
        }

        return $this->assemble(
            $currency,
            $summary,
            $case->charges->all(),
            $forPartner,
            $staff,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function forDamage(OrderDamage $damage, ?User $staff = null): array
    {
        $damage->loadMissing(['charges.walletTransaction.wallet', 'dispatch']);

        $currency = Currency::from($damage->currency_code);

        $summary = [
            $this->row('damage_max_value', $damage->max_recoverable_value, 'neutral', note: 'cases.financials.max_value_note'),
            $this->row('damage_loss', $damage->approved_loss_amount, 'debit', bearer: $damage->responsible_party),
        ];

        if ($damage->dispatch !== null) {
            $summary[] = $this->row('damage_delivery', $damage->dispatch->delivery_charge, 'debit', bearer: ReturnParty::Partner);
        }

        return $this->assemble($currency, $summary, $damage->charges->all(), $staff === null, $staff);
    }

    /**
     * @param  array<int, array<string, mixed>>  $summary
     * @param  array<int, ReturnCharge|DamageCharge>  $charges
     * @return array<string, mixed>
     */
    protected function assemble(Currency $currency, array $summary, array $charges, bool $forPartner, ?User $staff): array
    {
        $zero = Money::zero($currency);
        $deducted = $outstanding = $banij = $reversed = $zero;
        $ledger = [];

        foreach ($charges as $charge) {
            if ($charge->isReversal()) {
                $reversed = $reversed->plus($charge->amount);
            } elseif ($charge->status->stands()) {
                if ($charge->bearer === ReturnParty::Banij) {
                    $banij = $banij->plus($charge->amount);
                } elseif ($charge->status === ReturnChargeStatus::Outstanding) {
                    $outstanding = $outstanding->plus($charge->amount);
                } else {
                    $deducted = $deducted->plus($charge->amount);
                }
            }

            // The Partner is not shown Banij's own accounting.
            if ($forPartner && $charge->bearer === ReturnParty::Banij) {
                $ledger[] = $this->coveredRow($charge);

                continue;
            }

            $ledger[] = $this->ledgerRow($charge, $staff);
        }

        $net = $deducted->plus($outstanding);

        return [
            'summary' => $forPartner ? $this->forPartnerSummary($summary) : $summary,
            'ledger' => $ledger,
            'totals' => [
                'deducted' => $deducted->jsonSerialize(),
                'outstanding' => $outstanding->jsonSerialize(),
                'reversed' => $reversed->jsonSerialize(),
                'net_partner' => $net->jsonSerialize(),
                // Banij's own accounting is for staff.
                'banij_cost' => $forPartner ? null : $banij->jsonSerialize(),
            ],
        ];
    }

    /**
     * A Partner is told what falls on them. Where Banij bears a row the amount is
     * left out, and the row says it was covered.
     *
     * @param  array<int, array<string, mixed>>  $summary
     * @return array<int, array<string, mixed>>
     */
    protected function forPartnerSummary(array $summary): array
    {
        return array_map(function (array $row) {
            if (($row['bearer'] ?? null) === ReturnParty::Banij->value) {
                return [...$row, 'amount' => null, 'covered_by_banij' => true];
            }

            return [...$row, 'covered_by_banij' => false];
        }, $summary);
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(string $key, ?Money $amount, string $direction, ?ReturnParty $bearer = null, ?string $note = null): array
    {
        return [
            'key' => $key,
            'label' => (string) __('cases.financials.rows.'.$key),
            'amount' => $amount?->jsonSerialize(),
            'direction' => $direction,
            'bearer' => $bearer?->value,
            'bearer_label' => $bearer?->label(),
            'note' => $note === null ? null : (string) (str_starts_with($note, 'cases.') ? __($note) : $note),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function ledgerRow(ReturnCharge|DamageCharge $charge, ?User $staff): array
    {
        $kind = $charge->kind;
        $reversal = $charge->isReversal();

        return [
            'id' => $charge->public_id,
            'label' => (string) __('cases.financials.ledger.'.$kind->value.'.'.($reversal ? 'reversal' : 'charge')),
            'kind' => $kind->value,
            'entry_type' => $charge->entry_type,
            'bearer' => $charge->bearer->value,
            'bearer_label' => $charge->bearer->label(),
            'amount' => $charge->amount->jsonSerialize(),
            'direction' => $reversal ? 'credit' : 'debit',
            'status' => $charge->status->value,
            'status_label' => (string) __('cases.financials.status.'.$charge->status->value),
            'status_tone' => $charge->status->tone(),
            // Staff read the reason; it is theirs, not the Partner's.
            'reason' => $staff === null ? null : $charge->reason,
            'posted_at' => $charge->posted_at->toIso8601String(),
            'covered_by_banij' => false,
            'ledger_url' => $this->ledgerUrl($charge->walletTransaction, $staff),
        ];
    }

    /**
     * A Banij-borne row, as a Partner may see it: that it was covered, and nothing more.
     *
     * @return array<string, mixed>
     */
    protected function coveredRow(ReturnCharge|DamageCharge $charge): array
    {
        $reversal = $charge->isReversal();

        return [
            'id' => $charge->public_id,
            'label' => (string) __('cases.financials.ledger.'.$charge->kind->value.'.'.($reversal ? 'reversal' : 'charge')),
            'kind' => $charge->kind->value,
            'entry_type' => $charge->entry_type,
            'bearer' => ReturnParty::Banij->value,
            'bearer_label' => ReturnParty::Banij->label(),
            'amount' => null,
            'direction' => 'neutral',
            'status' => 'covered',
            'status_label' => (string) __('cases.financials.status.covered'),
            'status_tone' => 'neutral',
            'reason' => null,
            'posted_at' => $charge->posted_at->toIso8601String(),
            'covered_by_banij' => true,
            'ledger_url' => null,
        ];
    }

    /**
     * The wallet transaction a charge made, as the viewer may open it: staff with
     * `wallet.view` in the admin ledger, and a Partner in their own wallet.
     */
    protected function ledgerUrl(?WalletTransaction $transaction, ?User $staff): ?string
    {
        if ($transaction === null) {
            return null;
        }

        if ($staff === null) {
            return route('wallet.transactions.show', $transaction->public_id);
        }

        if (! $staff->can(PermissionCatalogue::name(PermissionModule::Wallet, PermissionAction::View))) {
            return null;
        }

        return route('admin.wallets.transactions.show', [$transaction->wallet->public_id, $transaction->public_id]);
    }
}
