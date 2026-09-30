<?php

namespace App\Domain\Notification\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Notification\Enums\SmsEvent;
use App\Domain\Notification\Policies\SmsSettingsPolicy;
use App\Domain\Notification\SmsEventSwitch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * Switch SMS on or off for one business event (§30).
 *
 * The third of §30's switches, beside the global one and the provider that
 * {@see ConfigureSms} holds. Read at delivery by {@see SmsEventSwitch}, so
 * switching an event off also stops the messages for it already waiting in
 * the queue.
 *
 * Audited like the global switch, and for the same reason: "nobody got a
 * payment confirmation last week" is a question about a setting somebody
 * changed.
 */
class ToggleSmsEvent
{
    public function __construct(
        protected SmsEventSwitch $events,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException for a one-time code
     */
    public function handle(User $actor, SmsEvent $event, bool $enabled): void
    {
        if (! SmsSettingsPolicy::canManage($actor)) {
            throw new AuthorizationException('You may not change SMS settings.');
        }

        $before = $this->events->isSwitchedOn($event->value);

        $this->events->set($event->value, $enabled, $actor->id);

        $this->audit->handle(new AuditEntry(
            action: $enabled ? 'sms.event_enabled' : 'sms.event_disabled',
            actorId: $actor->id,
            before: ['event' => $event->value, 'enabled' => $before],
            after: ['event' => $event->value, 'enabled' => $enabled],
            module: 'sms',
            // Whether customers are told about their own money.
            isSensitive: true,
        ));
    }
}
