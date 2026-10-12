<?php

namespace App\Integrations\Dns;

use App\Integrations\Dns\Contracts\TxtRecordResolver;
use App\Integrations\Dns\Exceptions\DnsLookupFailed;

/**
 * TXT records through the system resolver (D30 §3).
 *
 * A failed lookup is reported as a failure, never read as "no record" — the
 * caller must not mark anything verified, and must not mark it unverified on
 * the strength of a resolver that did not answer.
 */
class SystemTxtRecordResolver implements TxtRecordResolver
{
    public function txtRecords(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);

        if ($records === false) {
            throw new DnsLookupFailed('The DNS lookup for '.$name.' could not be made.');
        }

        $values = [];

        foreach ($records as $record) {
            // Long TXT values arrive split into strings; `txt` is their join.
            $text = $record['txt'] ?? (isset($record['entries']) ? implode('', (array) $record['entries']) : null);

            if (is_string($text) && $text !== '') {
                $values[] = trim($text, " \t\"");
            }
        }

        return $values;
    }
}
