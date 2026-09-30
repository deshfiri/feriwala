<?php

namespace App\Domain\Notification\Enums;

/**
 * Every business event the platform sends a text message for (§30).
 *
 * The admin SMS screen lists these, one switch each. A value here is the same
 * string the sender stamps on its `SmsMessage::$event`, so the switch and the
 * message agree without a lookup table between them. A guard test fails when
 * code sends an SMS under an event that is not listed here, so a new message
 * cannot slip in without its switch.
 *
 * **One-time codes cannot be switched off.** A verification or confirmation
 * code is the only way someone finishes signing up or confirms a cash order;
 * silencing it would lock them out rather than spare them a message. Those
 * events are listed so the screen is complete, and shown as always sent.
 */
enum SmsEvent: string
{
    case AccountActivated = 'account.activated';
    case PaymentReceived = 'payment.received';
    case WalletBalanceLow = 'wallet.balance_low';
    case KycDeadlineMissed = 'kyc_deadline_missed';
    case MobileVerification = 'mobile_verification';
    case SupplierMobileVerification = 'supplier_mobile_verification';
    case CodConfirmation = 'cod_confirmation';

    /**
     * Whether an administrator may switch this event's SMS off.
     */
    public function isSwitchable(): bool
    {
        return match ($this) {
            self::MobileVerification,
            self::SupplierMobileVerification,
            self::CodConfirmation => false,
            default => true,
        };
    }

    /**
     * @return list<self>
     */
    public static function switchable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $event) => $event->isSwitchable()));
    }

    /**
     * The event's name and what triggers it, in the reader's language.
     *
     * The value contains dots, so the lang array is fetched whole and indexed
     * rather than pathed into — `__('sms.events.account.activated')` would be
     * read as nested keys and silently come back as the key itself.
     *
     * @return array{title: string, description: string}
     */
    public function describe(): array
    {
        /** @var array<string, array{title: string, description: string}> $lines */
        $lines = (array) __('sms.events');

        return $lines[$this->value] ?? ['title' => $this->value, 'description' => ''];
    }
}
