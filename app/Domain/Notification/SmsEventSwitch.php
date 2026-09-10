<?php

namespace App\Domain\Notification;

use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Sms\SmsProviderManager;

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

        $setting = $this->settings->get(self::key($event));

        return $setting === null || (bool) $setting;
    }

    /**
     * Switch one event on or off.
     */
    public function set(string $event, bool $enabled, ?int $actorId = null): void
    {
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
