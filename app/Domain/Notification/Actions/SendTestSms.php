<?php

namespace App\Domain\Notification\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\PaymentLogRedactor;
use App\Integrations\Sms\Data\SmsMessage;
use App\Integrations\Sms\Data\SmsResult;
use App\Integrations\Sms\SmsProviderManager;
use App\Models\User;
use InvalidArgumentException;

/**
 * Sends one fixed message through a named provider, so an administrator can
 * tell whether the credentials they just entered actually work.
 *
 * It goes straight to the provider and not through the queue, the history or
 * the event switches: it is a test of the provider, not a notification, and it
 * should answer now with the provider's own reason if it fails. It is audited
 * with the recipient masked, and never with the message.
 */
class SendTestSms
{
    public function __construct(
        protected SmsProviderManager $providers,
        protected PaymentLogRedactor $redactor,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @throws InvalidArgumentException when the provider has no driver or is not configured
     */
    public function handle(User $actor, string $provider, string $recipient): SmsResult
    {
        if (! in_array($provider, $this->providers->available(), true)) {
            throw new InvalidArgumentException(__('sms.test.unavailable'));
        }

        $driver = $this->providers->driver($provider);

        if (! $driver->isConfigured()) {
            throw new InvalidArgumentException(__('sms.test.not_configured'));
        }

        $result = $driver->send(new SmsMessage(
            to: $recipient,
            body: __('sms.test.body'),
            userId: $actor->id,
        ));

        $this->audit->handle(new AuditEntry(
            action: 'sms.test_sent',
            actorId: $actor->id,
            after: [
                'provider' => $provider,
                'recipient' => $this->redactor->mask($recipient),
                'accepted' => $result->accepted,
                'error_code' => $result->errorCode,
            ],
            module: 'sms',
        ));

        return $result;
    }
}
