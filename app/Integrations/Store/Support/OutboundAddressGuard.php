<?php

namespace App\Integrations\Store\Support;

use App\Integrations\Store\Contracts\HostResolver;
use App\Integrations\Store\Exceptions\StorePlatformException;

/**
 * Keeps calls to partner-supplied addresses off Feriwala's own network (D29).
 *
 * A store address is typed in by a partner, so it could name `localhost`, a
 * cloud metadata service or a database on the private network. Before any call
 * the host is resolved and **every** address it resolves to must be public;
 * the call is then pinned to the address that was checked, so a DNS answer
 * that changes between the check and the request cannot slip through.
 */
final class OutboundAddressGuard
{
    public function __construct(
        private readonly HostResolver $resolver,
    ) {}

    /**
     * The checked address to connect to.
     *
     * @throws StorePlatformException when the host does not resolve or points anywhere private
     */
    public function addressFor(string $host): string
    {
        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            throw StorePlatformException::unreachable();
        }

        foreach ($addresses as $address) {
            if (! self::isPublic($address)) {
                throw StorePlatformException::addressRefused();
            }
        }

        return $addresses[0];
    }

    public static function isPublic(string $address): bool
    {
        // An IPv4 address written as IPv6 (`::ffff:127.0.0.1`) is judged as
        // the IPv4 address it is.
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $address, $mapped) === 1) {
            $address = $mapped[1];
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // Carrier-grade NAT space (100.64.0.0/10) is not routable from the
        // internet either, and the flags above do not cover it.
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($address);

            return ! ($long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255'));
        }

        return true;
    }
}
