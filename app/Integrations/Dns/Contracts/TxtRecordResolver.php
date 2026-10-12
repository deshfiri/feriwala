<?php

namespace App\Integrations\Dns\Contracts;

use App\Integrations\Dns\Exceptions\DnsLookupFailed;

/**
 * Reads the TXT records published for a DNS name (D30 §3).
 *
 * The evidence behind domain verification. Behind a contract so a test can say
 * what the DNS holds without the network, and so a slow or failing resolver
 * is a refusal with a reason rather than a guess.
 */
interface TxtRecordResolver
{
    /**
     * Every TXT string published at `$name`, unquoted. An empty list when the
     * name has none.
     *
     * @return list<string>
     *
     * @throws DnsLookupFailed when the lookup itself could not be made
     */
    public function txtRecords(string $name): array;
}
