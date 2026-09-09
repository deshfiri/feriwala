<?php

namespace App\Support\Security;

use App\Domain\Settings\SettingsRepository;
use Throwable;

/**
 * How long a signed-in session lasts (§6, §36).
 *
 * Configurable at runtime through the settings table — the same source the KYC
 * deadlines and fee rules already use — because "log people out sooner" is an
 * operational decision, and one that should not need a deploy to make.
 *
 * `config('session.lifetime')` remains the floor and the fallback. Nothing here
 * introduces a second configuration system: the setting overrides the config
 * value when present, and the config value is what applies when it is not.
 */
class SessionPolicy
{
    /** Minutes of inactivity before a session is discarded. */
    public const LIFETIME = 'security.session_lifetime_minutes';

    /**
     * Bounds, so a mistyped setting cannot lock everybody out or keep a session
     * alive indefinitely. Five minutes is short enough to be deliberate; thirty
     * days is the outer edge of anything defensible for an ERP holding money.
     */
    public const MINIMUM_MINUTES = 5;

    public const MAXIMUM_MINUTES = 43200;

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    /**
     * The configured lifetime in minutes.
     */
    public function lifetimeInMinutes(): int
    {
        $configured = (int) config('session.lifetime', 120);

        try {
            $setting = $this->settings->get(self::LIFETIME);
        } catch (Throwable) {
            /*
             * This is read before the session starts, on every web request. A
             * settings store that is unreachable — or a database that has not
             * been migrated yet — must degrade to the deployed configuration
             * rather than turn every page into a 500 at the session layer,
             * which is where the error handler has the least to work with.
             */
            return $configured;
        }

        if (! is_numeric($setting)) {
            return $configured;
        }

        return max(
            self::MINIMUM_MINUTES,
            min(self::MAXIMUM_MINUTES, (int) $setting),
        );
    }
}
