<?php

namespace App\Integrations\Dns\Exceptions;

use RuntimeException;

/**
 * A DNS lookup that could not be made at all — distinct from a name that
 * simply has no records.
 */
class DnsLookupFailed extends RuntimeException {}
