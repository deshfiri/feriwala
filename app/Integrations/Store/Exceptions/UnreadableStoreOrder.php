<?php

namespace App\Integrations\Store\Exceptions;

use InvalidArgumentException;

/**
 * An order a store sent that cannot be represented faithfully (D29) — an
 * amount with more precision than Taka has, a missing order number. Refused
 * rather than rounded or guessed; the message names the field, never its value.
 */
class UnreadableStoreOrder extends InvalidArgumentException {}
