<?php

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Enums\SmsStatus;
use App\Domain\Notification\Jobs\DeliverSmsMessage;
use App\Domain\Notification\Models\SmsMessageRecord;
use App\Integrations\Sms\Data\SmsMessage;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\Notification;

/**
 * The `sms` notification channel (§30, §30.2).
 *
 * SMS goes through Laravel's notification system rather than beside it. §30
 * calls SMS one delivery channel for notifications the platform already sends,
 * and a second business-event system would mean two places deciding what a
 * customer is told and two places to forget to update.
 *
 * This channel writes the record and hands delivery to a job. It does not talk
 * to a provider, which is what keeps a slow gateway out of the request and out
 * of the other channels' retries.
 *
 * **One message per business event.** `dedupe_key` is unique, so a retried
 * notification — the queue's own retry, a duplicated dispatch, a settlement
 * callback arriving twice — finds the row already there and adds nothing. The
 * retry that makes delivery reliable is the same retry that would otherwise make
 * it repetitive.
 */
class SmsChannel
{
    public function __construct(
        protected DatabaseManager $database,
    ) {}

    public function send(object $notifiable, Notification $notification): ?SmsMessageRecord
    {
        if (! method_exists($notification, 'toSms')) {
            return null;
        }

        $recipient = $this->recipientFor($notifiable);

        if ($recipient === null) {
            // Nothing to send to. Not a failure: plenty of accounts have no
            // mobile number, and a failed-SMS log full of those hides the real
            // ones.
            return null;
        }

        /** @var SmsMessage|null $message */
        $message = $notification->toSms($notifiable);

        if ($message === null || trim($message->body) === '') {
            return null;
        }

        $event = $message->event ?? $notification::class;

        $record = [
            'user_id' => $notifiable instanceof User ? $notifiable->id : null,
            'business_account_id' => $notifiable instanceof User
                ? $notifiable->businessAccount?->id
                : null,
            'event' => $event,
            'recipient' => $recipient,
            'locale' => $message->locale->value,
            'body' => $message->body,
            'segments' => $message->segments(),
            'status' => SmsStatus::Queued,
            'queued_at' => now(),
            'dedupe_key' => $this->dedupeKey($notification, $event, $recipient),
        ];

        try {
            /*
             * Wrapped, so the duplicate we are expecting cannot take the
             * caller's transaction with it. PostgreSQL aborts a whole
             * transaction on any failed statement, so catching the exception is
             * not enough on its own — inside a transaction this is a savepoint,
             * and rolling back to it leaves everything around it usable.
             */
            $created = $this->database->transaction(
                fn () => SmsMessageRecord::create($record)
            );
        } catch (UniqueConstraintViolationException) {
            // Somebody already wrote this exact message. The index settles it;
            // an application check would let two concurrent dispatches through.
            return null;
        }

        DeliverSmsMessage::dispatch($created->id);

        return $created;
    }

    /**
     * What makes this message this message.
     *
     * A notification may supply its own key when the event alone is not enough —
     * two renewal reminders for two subscriptions are different messages. The
     * default covers the common case: one event, one recipient, once.
     */
    protected function dedupeKey(Notification $notification, string $event, string $recipient): string
    {
        $key = method_exists($notification, 'smsDedupeKey')
            ? (string) $notification->smsDedupeKey()
            : $event;

        return hash('sha256', $key.'|'.$recipient);
    }

    protected function recipientFor(object $notifiable): ?string
    {
        $route = method_exists($notifiable, 'routeNotificationFor')
            ? $notifiable->routeNotificationFor('sms')
            : null;

        return is_string($route) && trim($route) !== '' ? trim($route) : null;
    }
}
