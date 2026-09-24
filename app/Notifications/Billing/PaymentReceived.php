<?php

namespace App\Notifications\Billing;

use App\Integrations\Sms\Data\SmsMessage;
use App\Support\Localization\Locale;
use App\Support\Money\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells somebody their payment arrived (§26.4, §30, D20).
 *
 * Non-optional under D20, which lists payment success among the notifications a
 * user cannot turn off. Somebody who has just handed over money is entitled to
 * be told it landed, by the channel they are most likely to see.
 *
 * The reference is in the message deliberately. It is what a support
 * conversation needs, and the number the person will be asked for.
 */
class PaymentReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $reference,
        public readonly Money $amount,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail', 'sms'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('sms.templates.payment_received_subject'))
            ->line(__('sms.templates.payment_received', [
                'amount' => $this->amount->format(),
                'reference' => $this->reference,
            ]));
    }

    public function toSms(object $notifiable): SmsMessage
    {
        return new SmsMessage(
            to: '',
            body: __('sms.templates.payment_received', [
                'amount' => $this->amount->format(),
                'reference' => $this->reference,
            ]),
            locale: Locale::parse($notifiable->locale ?? null),
            event: 'payment.received',
        );
    }

    /**
     * One message per payment, not per delivery attempt.
     *
     * A gateway sends the same notification several times and settlement is
     * idempotent — but "idempotent" has to reach the customer's phone too.
     */
    public function smsDedupeKey(): string
    {
        return 'payment.received:'.$this->reference;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'payment.received',
            'reference' => $this->reference,
            'amount' => $this->amount->toDecimal(),
            'currency' => $this->amount->currency->value,
        ];
    }
}
