<?php

namespace App\Notifications\Wallet;

use App\Domain\Wallet\Enums\WalletBalanceState;
use App\Integrations\Sms\Data\SmsMessage;
use App\Support\Localization\Locale;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The wallet has fallen below what the account is required to hold (§24.3).
 *
 * §24.3 asks for a dashboard notification and an SMS where enabled, and for the
 * required top-up to be shown. The amount is in every channel for that reason:
 * "your balance is low" without a figure is a message that cannot be acted on.
 *
 * The deadline goes in where there is one. An account that knows it has eleven
 * days behaves differently from one that thinks it has none — and differently
 * again from one that has already run out.
 */
class WalletBalanceLow extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly WalletBalanceState $state,
        public readonly Money $shortfall,
        public readonly Money $required,
        public readonly ?CarbonImmutable $graceEndsAt = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // Dashboard first and always: the ERP entry is the one the account
        // holder is certain to see. SMS goes where §30 has it enabled, and the
        // channel itself decides that rather than this message.
        return ['database', 'mail', 'sms'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('wallet.notice.subject'))
            ->line(__('wallet.notice.body', [
                'shortfall' => $this->shortfall->format(),
                'required' => $this->required->format(),
            ]));

        if ($this->graceEndsAt !== null) {
            $message->line(__('wallet.notice.grace', [
                'date' => $this->graceEndsAt->toFormattedDateString(),
            ]));
        }

        return $message->action(__('wallet.notice.action'), route('wallet.show'));
    }

    public function toSms(object $notifiable): SmsMessage
    {
        return new SmsMessage(
            to: '',
            body: __('wallet.notice.sms', [
                'shortfall' => $this->shortfall->format(),
            ]),
            locale: Locale::parse($notifiable->locale ?? null),
            event: 'wallet.balance_low',
        );
    }

    /**
     * One message per shortfall, not per sweep.
     *
     * The balance check runs daily and the shortfall lasts until it is paid. A
     * key that changed every run would text somebody every morning about the
     * same thing, which is how people learn to ignore the messages that matter.
     */
    public function smsDedupeKey(): string
    {
        return 'wallet.balance_low:'.$this->state->value.':'.$this->shortfall->minorUnits;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'wallet.balance_low',
            'state' => $this->state->value,
            'shortfall_minor' => $this->shortfall->minorUnits,
            'required_minor' => $this->required->minorUnits,
            'currency' => $this->shortfall->currency->value,
            'grace_ends_at' => $this->graceEndsAt?->toIso8601String(),
        ];
    }
}
