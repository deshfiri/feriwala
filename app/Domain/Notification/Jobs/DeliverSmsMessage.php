<?php

namespace App\Domain\Notification\Jobs;

use App\Domain\Notification\Enums\SmsStatus;
use App\Domain\Notification\Models\SmsMessageRecord;
use App\Domain\Notification\SmsEventSwitch;
use App\Integrations\Sms\Contracts\SmsProvider;
use App\Integrations\Sms\SmsProviderManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Hands one message to a provider (§30.2).
 *
 * §30.2 requires delivery to run through queues so it never slows the ERP down.
 * It is a job of its own rather than work done inside the notification, and that
 * separation is the point: a provider timing out must retry **the text message**,
 * not the whole notification — retrying that would send the email again too, and
 * the customer would receive one payment confirmation by SMS and four by mail.
 *
 * The record is written before this runs, so a message exists to look at whether
 * or not the provider ever answers.
 *
 * Retries are for transient failures only. A rejected number or an exhausted
 * balance will be rejected identically on the third attempt, so those are
 * recorded and left alone ({@see SmsResult::rejected()}).
 */
class DeliverSmsMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Three attempts, then it is a failure worth a person looking at. */
    public int $tries = 3;

    /**
     * A minute, then five. A provider having a bad moment recovers inside that;
     * one that is properly down is not helped by hammering it.
     *
     * @var array<int, int>
     */
    public $backoff = [60, 300];

    public function __construct(
        public readonly int $recordId,
    ) {
        $this->onQueue((string) config('sms.queue', 'sms'));
    }

    /**
     * The provider arrives as the contract rather than from the manager, so the
     * one binding decides who sends — and a test, or a future per-account
     * routing rule, has one place to change it.
     */
    public function handle(
        SmsProviderManager $providers,
        SmsEventSwitch $events,
        SmsProvider $provider,
    ): void {
        $record = SmsMessageRecord::query()->find($this->recordId);

        if ($record === null || $record->status !== SmsStatus::Queued) {
            // Already settled, or the record is gone. Either way there is
            // nothing to send, and sending anyway would send it twice.
            return;
        }

        /*
         * The switches are read here rather than at composition, so turning SMS
         * off stops what is already queued as well as what has not been written
         * yet — which is what somebody turning it off in a hurry means.
         */
        if (! $events->isEnabledFor($record->event)) {
            $record->forceFill([
                'status' => SmsStatus::Suppressed,
                'error' => 'SMS is switched off for this event.',
            ])->save();

            return;
        }

        if (! $providers->canSend()) {
            $record->forceFill([
                'status' => SmsStatus::Suppressed,
                'error' => 'No SMS provider is available.',
            ])->save();

            return;
        }

        $record->forceFill([
            'attempts' => $record->attempts + 1,
            'provider' => $provider->name(),
        ])->save();

        try {
            $result = $provider->send($record->toProviderMessage());
        } catch (Throwable $exception) {
            // A thrown provider is a fault rather than a refusal, so it is worth
            // another attempt — but the record says what happened either way.
            $record->forceFill([
                'error' => $exception->getMessage(),
            ])->save();

            $this->recordExhaustion($record, $exception->getMessage());

            throw $exception;
        }

        if ($result->accepted) {
            $record->forceFill([
                'status' => SmsStatus::Sent,
                'provider_reference' => $result->providerReference,
                'cost' => $result->cost,
                'sent_at' => now(),
                'error' => null,
            ])->save();

            return;
        }

        if ($result->retryable && $this->attempts() < $this->tries) {
            $record->forceFill(['error' => $result->error])->save();

            $this->release($this->backoff[$this->attempts() - 1] ?? 300);

            return;
        }

        // A refusal, or the last attempt. Either way it is over.
        $record->forceFill([
            'status' => SmsStatus::Failed,
            'error' => $result->error,
            'failed_at' => now(),
        ])->save();
    }

    /**
     * The queue has given up. Say so on the record rather than only in a job
     * table nobody reads (§30.2's failed-SMS log).
     */
    public function failed(?Throwable $exception): void
    {
        $record = SmsMessageRecord::query()->find($this->recordId);

        $record?->forceFill([
            'status' => SmsStatus::Failed,
            'error' => $exception?->getMessage() ?? 'Delivery failed.',
            'failed_at' => now(),
        ])->save();
    }

    /**
     * Mark the record failed on the attempt that will not be retried.
     *
     * `failed()` covers the queue's own accounting, but a synchronous connection
     * — tests, and a small deployment running `sync` — never calls it.
     */
    protected function recordExhaustion(SmsMessageRecord $record, string $error): void
    {
        if ($this->attempts() >= $this->tries) {
            $record->forceFill([
                'status' => SmsStatus::Failed,
                'error' => $error,
                'failed_at' => now(),
            ])->save();
        }
    }
}
