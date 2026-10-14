<?php

namespace App\Domain\Website;

use App\Domain\Website\Data\IssuedCredential;

/**
 * The lines an authorised operator pastes into one storefront installation's
 * server-side `.env` (D32 §2, P3 ER-3).
 *
 * Exactly the three variables the storefront reads for its ERP connection and
 * checks at boot, in the format it checks them. The website's public id and
 * its hosts are deliberately not here: the storefront keeps those in its
 * public `site.config.json`, which refuses anything that looks like a key.
 *
 * Built only from a freshly issued or rotated credential, and returned only in
 * the one response that issued it — never stored, flashed or logged.
 */
class StorefrontEnvironmentBlock
{
    public const BASE_URL = 'BANIJ_ERP_BASE_URL';

    public const KEY_ID = 'BANIJ_CREDENTIAL_KEY_ID';

    public const SECRET = 'BANIJ_CREDENTIAL_SECRET';

    public static function for(IssuedCredential $issued, string $erpOrigin): string
    {
        return implode("\n", [
            '# Banij Storefront — ERP credential (server-side only; never commit)',
            self::BASE_URL.'='.self::origin($erpOrigin),
            self::KEY_ID.'='.$issued->credential->key_id,
            self::SECRET.'='.$issued->secret,
        ])."\n";
    }

    /**
     * The ERP's origin as the storefront expects it: scheme, host and port,
     * with no path.
     */
    public static function origin(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return rtrim($url, '/');
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * The storefront refuses anything but HTTPS for the ERP origin.
     */
    public static function isAcceptedOrigin(string $url): bool
    {
        return str_starts_with(self::origin($url), 'https://');
    }
}
