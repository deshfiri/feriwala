<?php

namespace App\Domain\Notification;

use App\Domain\Notification\Enums\SmsEvent;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Sms\SmsProviderManager;
use InvalidArgumentException;

/**
 * Whether SMS is on for one particular event (§30).
 *
 * §30 requires SMS to be disableable globally, by provider **and by notification
 * event**. This is the third of those, and it is read at delivery rather than at
 * composition — so switching an event off stops the messages already sitting in
 * the queue as well as the ones not written yet, which is what somebody turning
 * it off in a hurry actually means.
 *
 * Events are **on unless switched off**. A new notification that silently sent
 * nothing until somebody found the right setting would be a worse default than
 * one that sends.
 */
class SmsEventSwitch
{
    public function __construct(
        protected SettingsRepository $settings,
        protected SmsProviderManager $providers,
    ) {}

    public function isEnabledFor(string $event): bool
    {
        if (! $this->providers->isEnabled()) {
            return false;
        }

        return $this->isSwitchedOn($event);
    }

    /**
     * This event's own switch, ignoring the global one — what the admin screen
     * shows beside each event. A one-time code is always on.
     */
    public function isSwitchedOn(string $event): bool
    {
        if (SmsEvent::tryFrom($event)?->isSwitchable() === false) {
            return true;
        }

        $setting = $this->settings->get(self::key($event));

        return $setting === null || (bool) $setting;
    }

    /**
     * Switch one event on or off.
     *
     * @throws InvalidArgumentException for a one-time code, which cannot be
     *                                  switched off without locking people out
     */
    public function set(string $event, bool $enabled, ?int $actorId = null): void
    {
        if (SmsEvent::tryFrom($event)?->isSwitchable() === false) {
            throw new InvalidArgumentException("SMS for [{$event}] carries a one-time code and cannot be switched off.");
        }

        $this->settings->define(
            self::key($event),
            'sms',
            SettingType::Boolean,
            true,
            label: 'SMS for '.$event,
        );

        $this->settings->set(self::key($event), $enabled, $actorId);
    }

    public static function key(string $event): string
    {
        return 'sms.event.'.$event;
    }
}
