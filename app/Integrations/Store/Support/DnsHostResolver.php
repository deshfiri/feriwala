<?php

namespace App\Integrations\Store\Support;

use App\Integrations\Store\Contracts\HostResolver;

/**
 * The real resolver: the system's DNS, A and AAAA records both (D29).
 */
final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (! is_array($records)) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
