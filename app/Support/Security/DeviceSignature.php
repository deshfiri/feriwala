<?php

namespace App\Support\Security;

/**
 * What a browser says it is, in words a person recognises (§6).
 *
 * "Chrome on Windows" is the only form of this anybody can act on. A user agent
 * string is not something a customer support call can work with, and a session
 * list that shows one is a session list nobody ends the right row from.
 *
 * Deliberately small and deliberately approximate. Full user-agent parsing is a
 * library and a data file that go stale; this recognises the handful of browsers
 * and platforms the ERP is actually used from and says "Unknown device" for the
 * rest, which is honest. Nothing security-critical rests on the answer — the
 * fingerprint decides whether a device is new, and the label only describes it.
 */
class DeviceSignature
{
    /**
     * Browser tokens, most specific first.
     *
     * Order matters more than the list does: every Chromium browser also says
     * "Chrome", and Edge says both, so the general names have to come last or
     * every browser is Chrome.
     *
     * @var array<string, string>
     */
    protected const BROWSERS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'SamsungBrowser' => 'Samsung Internet',
        'Firefox' => 'Firefox',
        'Chrome' => 'Chrome',
        'Safari' => 'Safari',
    ];

    /**
     * @var array<string, string>
     */
    protected const PLATFORMS = [
        'Windows' => 'Windows',
        'Android' => 'Android',
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Macintosh' => 'macOS',
        'Mac OS X' => 'macOS',
        'Linux' => 'Linux',
    ];

    /**
     * A readable name for the device behind this user agent.
     */
    public function label(?string $userAgent): string
    {
        $agent = trim((string) $userAgent);

        if ($agent === '') {
            return __('Unknown device');
        }

        $browser = $this->match(self::BROWSERS, $agent);
        $platform = $this->match(self::PLATFORMS, $agent);

        if ($browser !== null && $platform !== null) {
            return __(':browser on :platform', ['browser' => $browser, 'platform' => $platform]);
        }

        return $browser ?? $platform ?? __('Unknown device');
    }

    /**
     * A stable hash of the device, for recognising one that has been seen before.
     *
     * The whole user agent rather than the label, because two different browsers
     * that both render as "Chrome on Windows" are two different devices as far
     * as "was that you?" is concerned. A blank agent hashes to its own bucket
     * rather than to nothing, so scripted sign-ins do not all look like one
     * familiar device.
     */
    public function fingerprint(?string $userAgent): string
    {
        return hash('sha256', trim((string) $userAgent));
    }

    /**
     * @param  array<string, string>  $tokens
     */
    protected function match(array $tokens, string $agent): ?string
    {
        foreach ($tokens as $needle => $name) {
            if (str_contains($agent, $needle)) {
                return $name;
            }
        }

        return null;
    }
}
