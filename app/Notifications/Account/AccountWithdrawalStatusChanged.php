<?php

namespace App\Notifications\Account;

use App\Domain\Withdrawal\Actions\AdvanceAccountWithdrawalStatus;
use App\Domain\Withdrawal\Enums\AccountWithdrawalStatus;
use App\Domain\Withdrawal\Models\AccountWithdrawal;
use App\Notifications\Supplier\SupplierWithdrawalStatusChanged;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an account holder their withdrawal request was rejected, failed, or
 * paid (§27, mirrors {@see SupplierWithdrawalStatusChanged}
 * for a different owner, but follows this domain's own plain hardcoded-text
 * convention — see {@see AccountSuspended} — rather than the Supplier
 * lifecycle base's `notification.events` lookup).
 *
 * Only `Rejected`, `Failed` and `Paid` are notified: the intermediate moves
 * ({@see AdvanceAccountWithdrawalStatus}) are
 * staff bookkeeping the account holder does not need to hear about.
 */
class AccountWithdrawalStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly AccountWithdrawal $withdrawal,
        public readonly ?string $reason = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->subject())
            ->line($this->description());

        if ($this->reason !== null && trim($this->reason) !== '') {
            $message->line($this->reason);
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'account.withdrawal_'.$this->withdrawal->status->value,
            'withdrawal_reference' => $this->withdrawal->reference,
            'withdrawal_id' => $this->withdrawal->public_id,
            'status' => $this->withdrawal->status->value,
            'amount' => $this->withdrawal->amount->jsonSerialize(),
            'reason' => $this->reason,
        ];
    }

    private function subject(): string
    {
        return match ($this->withdrawal->status) {
            AccountWithdrawalStatus::Rejected => __('Your withdrawal request was rejected'),
            AccountWithdrawalStatus::Failed => __('Your withdrawal payout did not go through'),
            AccountWithdrawalStatus::Paid => __('Your withdrawal has been paid'),
            default => __('Your withdrawal request status has changed'),
        };
    }

    private function description(): string
    {
        return match ($this->withdrawal->status) {
            AccountWithdrawalStatus::Rejected => __('Your withdrawal request :reference was rejected and the reserved amount has been returned to your wallet.', ['reference' => $this->withdrawal->reference]),
            AccountWithdrawalStatus::Failed => __('The payout for withdrawal :reference did not go through and the reserved amount has been returned to your wallet.', ['reference' => $this->withdrawal->reference]),
            AccountWithdrawalStatus::Paid => __('Withdrawal :reference has been paid.', ['reference' => $this->withdrawal->reference]),
            default => __('Withdrawal :reference has changed status.', ['reference' => $this->withdrawal->reference]),
        };
    }
}
