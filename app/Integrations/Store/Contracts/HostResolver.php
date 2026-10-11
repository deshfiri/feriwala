<?php

namespace App\Integrations\Store\Contracts;

/**
 * Turns a host name into the addresses it points at (D29).
 *
 * A contract so the outbound guard can be tested without the network.
 */
interface HostResolver
{
    /**
     * @return list<string> every IPv4 and IPv6 address, empty when it does not resolve
     */
    public function resolve(string $host): array;
}
