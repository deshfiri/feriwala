<?php

namespace App\Integrations\Payment;

use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sending the payer to the gateway's own page (§26.4, D12).
 *
 * **A gateway is another origin, so the browser has to go there itself.** Every
 * page that starts a payment is an Inertia page, and its submit is an XHR: a
 * `302` to the gateway would be followed by that XHR, which the gateway refuses
 * as a cross-origin request — the payment never opens and the console shows a
 * CORS error instead. {@see Inertia::location()} answers an Inertia request
 * with `409` and `X-Inertia-Location`, which the client turns into a full page
 * visit, and answers an ordinary form post with the plain redirect it expects.
 *
 * One place for every purpose — activation, renewal, package change, wallet
 * top-up, wholesale and website orders — so a payment flow added later cannot
 * quietly go back to a redirect the browser cannot follow.
 *
 * **The address is checked before anybody is sent to it.** A gateway that
 * answers with something malformed, with a scheme that is not https, or with
 * credentials embedded in the address is treated as unavailable: the payment
 * stays open to be tried again rather than the payer being sent somewhere
 * nobody meant. Plain http is allowed only for a local address while testing,
 * where the sandbox has no certificate.
 */
class GatewayNavigation
{
    /**
     * Where a local gateway stub may live when it has no certificate.
     */
    protected const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1'];

    /**
     * @throws GatewayUnavailable when the address cannot be opened safely
     */
    public static function to(string $url, string $gateway): Response
    {
        if (! self::isOpenable($url)) {
            throw GatewayUnavailable::forGateway($gateway, 'it answered with an address that cannot be opened');
        }

        return Inertia::location($url);
    }

    /**
     * Refuse a gateway that answered with an address nobody can be sent to.
     *
     * Called where the session is opened, so the payment stays as it was and the
     * payer is told the gateway is unavailable — which it is.
     *
     * @throws GatewayUnavailable
     */
    public static function ensureOpenable(string $url, string $gateway): void
    {
        if (! self::isOpenable($url)) {
            throw GatewayUnavailable::forGateway($gateway, 'it answered with an address that cannot be opened');
        }
    }

    /**
     * Whether a browser may be sent to this address at all.
     */
    public static function isOpenable(string $url): bool
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['host'], $parts['scheme']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = mb_strtolower($parts['scheme']);

        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http'
            && in_array(mb_strtolower($parts['host']), self::LOCAL_HOSTS, true)
            && app()->environment(['local', 'testing']);
    }
}
